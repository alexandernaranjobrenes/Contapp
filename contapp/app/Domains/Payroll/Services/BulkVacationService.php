<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\VacationMovement;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de vacaciones para muchos trabajadores de una vez.
 *
 * ── Los casos que resuelve ───────────────────────────────────────────────
 *
 * El cierre de fin de año: la planta para del 24 al 31 y hay que rebajarle
 * esos días a todo el mundo. Un ajuste de saldos iniciales al arrancar con
 * el sistema. Una acreditación extraordinaria por convenio.
 *
 * Hacerlo uno por uno con cincuenta personas no es solo lento: es donde se
 * salta a alguien, y ese alguien se entera un año después cuando reclama.
 *
 * ── Todo o nada, y por qué ───────────────────────────────────────────────
 *
 * Si a un trabajador el movimiento lo dejaría en saldo negativo, NO se
 * aplica ninguno. La alternativa —aplicar los que caben y saltarse los
 * otros— deja un cierre a medias: unos con los días rebajados y otros no,
 * sin nada que indique cuáles quedaron pendientes. Con cincuenta personas,
 * averiguarlo es revisar cincuenta saldos a mano.
 *
 * El aviso dice a quién le falta saldo y cuánto, para poder decidir: darle
 * vacaciones adelantadas con un ajuste, o sacarlo de la selección.
 *
 * ── Por qué no hay «aplicar a todos» sin lista ───────────────────────────
 *
 * El proceso recibe ids explícitos. Un botón que dijera «a todos los
 * activos» parece cómodo hasta que alguien lo usa sin mirar quién entró la
 * semana pasada. La lista se elige y se ve antes de aplicar.
 */
class BulkVacationService
{
    /**
     * @param  int[]  $employeeIds
     * @return array{applied: int, days: string, employees: array<int, string>}
     */
    public function apply(
        Company $company,
        array $employeeIds,
        string $type,
        string $movementDate,
        string $days,
        ?string $fromDate,
        ?string $toDate,
        ?string $notes,
        ?int $userId,
    ): array {
        if ($employeeIds === []) {
            throw new InvalidPayrollException('No se seleccionó ningún trabajador.');
        }

        $employees = Employee::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('id', $employeeIds)
            ->orderBy('code')
            ->get();

        if ($employees->count() !== count(array_unique($employeeIds))) {
            throw new InvalidPayrollException('Alguno de los trabajadores seleccionados no existe en esta compañía.');
        }

        // Los disfrutes y pagos rebajan: se guardan en negativo para que el
        // saldo siga siendo una suma simple.
        $signed = in_array($type, VacationMovement::REDUCING_TYPES, true)
            ? (bccomp($days, '0', 4) > 0 ? bcmul($days, '-1', 4) : $days)
            : $days;

        // Se revisa a TODOS antes de escribir a ninguno.
        if (bccomp($signed, '0', 4) < 0 && $type !== 'adjustment') {
            $short = [];

            foreach ($employees as $employee) {
                $balance = $employee->vacationBalance();
                $resulting = bcadd($balance, $signed, 4);

                if (bccomp($resulting, '0', 4) < 0) {
                    $short[] = "{$employee->code} — {$employee->fullName()} (tiene {$balance})";
                }
            }

            if ($short !== []) {
                throw new InvalidPayrollException(
                    'No se aplicó nada: a '.count($short).' trabajador(es) el movimiento los dejaría con saldo '.
                    'negativo. '.implode('; ', array_slice($short, 0, 8)).
                    (count($short) > 8 ? ' …y otros más.' : '').
                    ' Sacalos de la selección, o registrales el adelanto como ajuste.'
                );
            }
        }

        return DB::transaction(function () use ($company, $employees, $type, $movementDate, $signed, $fromDate, $toDate, $notes, $userId) {
            foreach ($employees as $employee) {
                VacationMovement::create([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'type' => $type,
                    'movement_date' => $movementDate,
                    'days' => $signed,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'notes' => $notes,
                    'created_by' => $userId,
                ]);
            }

            return [
                'applied' => $employees->count(),
                'days' => $signed,
                'employees' => $employees->map(fn (Employee $e) => $e->code)->all(),
            ];
        });
    }
}
