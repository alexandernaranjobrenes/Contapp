<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\VacationMovement;
use App\Models\User;

/** Un movimiento de vacaciones a mano (VacationController::store): disfrute, pago o ajuste. */
final class RecordVacationAction extends BaseContiAction
{
    /** Los que se registran a mano: la acreditación la hace la planilla. */
    private const TYPES = ['taken' => 'Disfrute', 'paid' => 'Pago en efectivo', 'adjustment' => 'Ajuste'];

    public function key(): string
    {
        return 'registrar_vacaciones';
    }

    public function label(): string
    {
        return 'Registrar vacaciones';
    }

    public function description(): string
    {
        return 'Registra días de vacaciones disfrutados, pagados en efectivo o un ajuste. Un disfrute o un pago rebajan el saldo; '
            .'si lo dejarían en negativo, solo se acepta como ajuste.';
    }

    public function screen(): string
    {
        return 'payroll.vacations';
    }

    public function fields(): array
    {
        return [
            'empleado' => 'código del trabajador',
            'tipo' => 'disfrute | pago | ajuste',
            'dias' => 'cantidad de días (en un ajuste, negativa para rebajar)',
            'fecha' => 'AAAA-MM-DD del movimiento; por defecto hoy',
            'desde' => 'AAAA-MM-DD (opcional)',
            'hasta' => 'AAAA-MM-DD (opcional)',
            'monto' => 'monto pagado, en un pago (opcional)',
            'notas' => 'opcional; en un ajuste, el motivo',
        ];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $input['fecha'] = ($input['fecha'] ?? '') !== '' ? $input['fecha'] : now()->format('Y-m-d');

        $this->validate($input, [
            'empleado' => ['required', 'string'],
            'tipo' => ['required', 'string'],
            'dias' => ['required', 'numeric', 'not_in:0'],
            'fecha' => ['required', 'date'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'monto' => ['nullable', 'numeric', 'gte:0'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = $this->byCode(Employee::class, $input['empleado'], 'empleado', 'un trabajador');
        $type = ContiFilters::keyFor((string) $input['tipo'], self::TYPES);

        if (! array_key_exists($type, self::TYPES)) {
            $this->fail('tipo', 'El tipo es disfrute, pago o ajuste.');
        }

        // Un disfrute o un pago rebajan: se guardan en negativo, como en la pantalla.
        $days = (string) $input['dias'];
        if (in_array($type, VacationMovement::REDUCING_TYPES, true) && bccomp($days, '0', 4) > 0) {
            $days = bcmul($days, '-1', 4);
        }

        $balance = $employee->vacationBalance();
        $resulting = bcadd($balance, $days, 4);

        if (bccomp($resulting, '0', 4) < 0 && $type !== 'adjustment') {
            $this->fail('dias', "El trabajador tiene {$balance} día(s) acumulado(s) y esto lo dejaría en {$resulting}. Si querés adelantarle vacaciones, registralo como ajuste con el motivo.");
        }

        return new PreparedAction(
            title: self::TYPES[$type]." de vacaciones para {$employee->code} {$employee->fullName()}",
            items: $this->items([
                'Trabajador' => "{$employee->code} {$employee->fullName()}",
                'Tipo' => self::TYPES[$type],
                'Días' => rtrim(rtrim($days, '0'), '.'),
                'Fecha' => date('Y-m-d', strtotime($input['fecha'])),
                'Desde' => $input['desde'] ?? null,
                'Hasta' => $input['hasta'] ?? null,
                'Monto' => ($input['monto'] ?? '') !== '' ? $this->money($input['monto']) : null,
                'Notas' => $input['notas'] ?? null,
                'Saldo' => rtrim(rtrim($balance, '0'), '.').' → '.rtrim(rtrim($resulting, '0'), '.').' días',
            ]),
            payload: [
                'employee_id' => $employee->id,
                'type' => $type,
                'movement_date' => date('Y-m-d', strtotime($input['fecha'])),
                'days' => $days,
                'from_date' => ($input['desde'] ?? '') !== '' ? date('Y-m-d', strtotime($input['desde'])) : null,
                'to_date' => ($input['hasta'] ?? '') !== '' ? date('Y-m-d', strtotime($input['hasta'])) : null,
                'amount' => ($input['monto'] ?? '') !== '' ? (string) $input['monto'] : null,
                'notes' => ($input['notas'] ?? '') !== '' ? $input['notas'] : null,
            ],
            input: $input,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        VacationMovement::create([...$prepared->payload, 'company_id' => $company->id, 'created_by' => $user->id]);

        return new ActionResult('Movimiento de vacaciones registrado.', route('vacations.index'), 'Ver vacaciones');
    }
}
