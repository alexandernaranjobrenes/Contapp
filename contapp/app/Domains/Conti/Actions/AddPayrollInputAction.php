<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollInput;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Models\User;

/**
 * Un movimiento de un período de planilla —horas extra, un bono, un rebajo
 * puntual—, como «Agregar movimiento» (PayrollPeriodController::storeInput).
 */
final class AddPayrollInputAction extends BaseContiAction
{
    public function key(): string
    {
        return 'registrar_movimiento_planilla';
    }

    public function label(): string
    {
        return 'Registrar un movimiento de planilla';
    }

    public function description(): string
    {
        return 'Agrega horas extra, un bono o un rebajo puntual a un trabajador en un período abierto o calculado. Después hay que recalcular la planilla.';
    }

    public function screen(): string
    {
        return 'payroll.periods';
    }

    public function fields(): array
    {
        return [
            'periodo' => 'id o nombre del período de planilla',
            'empleado' => 'código del trabajador',
            'concepto' => 'código del concepto de planilla (de «conceptos-planilla»)',
            'monto' => 'el importe, si el concepto se digita por monto',
            'horas' => 'la cantidad de horas, si el concepto se paga por horas',
            'notas' => 'opcional',
        ];
    }

    public function form(Company $company): array
    {
        return [
            $this->field('periodo', 'Período de planilla', 'buscar', ['requerido' => true, 'fuente' => 'periodos-planilla', 'ayuda' => 'Uno abierto: los calculados o cerrados no admiten movimientos.']),
            $this->field('empleado', 'Trabajador', 'buscar', ['requerido' => true, 'fuente' => 'empleados']),
            $this->field('concepto', 'Concepto', 'buscar', ['requerido' => true, 'fuente' => 'conceptos-planilla']),
            $this->field('monto', 'Monto', 'monto', ['ayuda' => 'Si el concepto se digita por monto.']),
            $this->field('horas', 'Horas', 'numero', ['ayuda' => 'Si el concepto se paga por horas.']),
            $this->field('notas', 'Notas', 'texto'),
        ];
    }

    /** La planilla abierta más reciente. */
    public function suggest(array $values, Company $company): array
    {
        if ($this->given($values, 'periodo')) {
            return [];
        }

        $open = PayrollPeriod::orderByDesc('start_date')->limit(24)->get()->first(fn (PayrollPeriod $period) => $period->isRecalculable());

        return $open ? ['periodo' => $this->suggestion($open->id, "Es la planilla abierta más reciente: {$open->name}.")] : [];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $this->validate($input, [
            'periodo' => ['required'],
            'empleado' => ['required', 'string'],
            'concepto' => ['required', 'string'],
            'monto' => ['nullable', 'numeric'],
            'horas' => ['nullable', 'numeric'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);

        $period = ctype_digit((string) $input['periodo'])
            ? PayrollPeriod::find((int) $input['periodo'])
            : PayrollPeriod::where('name', $input['periodo'])->first();

        if ($period === null) {
            $this->fail('periodo', "No existe el período de planilla «{$input['periodo']}».");
        }

        if (! $period->isRecalculable()) {
            $this->fail('periodo', "El período «{$period->name}» ya está ".mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status).': no admite movimientos nuevos.');
        }

        $employee = $this->byCode(Employee::class, $input['empleado'], 'empleado', 'un trabajador');
        $concept = $this->byCode(PayrollConcept::class, $input['concepto'], 'concepto', 'un concepto de planilla');
        $amount = ($input['monto'] ?? '') !== '' ? (string) $input['monto'] : null;
        $hours = ($input['horas'] ?? '') !== '' ? (string) $input['horas'] : null;

        if ($concept->calculation === 'hours' && $hours === null) {
            $this->fail('horas', "El concepto {$concept->code} se paga por horas: indicá la cantidad.");
        }

        if ($concept->calculation === 'amount' && $amount === null) {
            $this->fail('monto', "El concepto {$concept->code} se digita por monto: indicá el importe.");
        }

        return new PreparedAction(
            title: "Movimiento de planilla para {$employee->code} {$employee->fullName()}",
            items: $this->items([
                'Período' => $period->name,
                'Trabajador' => "{$employee->code} {$employee->fullName()}",
                'Concepto' => "{$concept->code} {$concept->name}",
                'Monto' => $amount !== null ? $this->money($amount) : null,
                'Horas' => $hours,
                'Notas' => $input['notas'] ?? null,
                'Después' => 'Hay que recalcular la planilla para que se aplique.',
            ]),
            payload: [
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'payroll_concept_id' => $concept->id,
                'amount' => $amount,
                'quantity' => $hours,
                'notes' => ($input['notas'] ?? '') !== '' ? $input['notas'] : null,
            ],
            input: $input,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        PayrollInput::create([...$prepared->payload, 'company_id' => $company->id, 'created_by' => $user->id]);

        return new ActionResult(
            'Movimiento registrado. Recalculá la planilla para que se aplique.',
            route('payroll-periods.show', $prepared->payload['payroll_period_id']),
            'Ver el período de planilla',
        );
    }
}
