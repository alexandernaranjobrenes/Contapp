<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\PersonnelAction;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * La bitácora de acciones de personal: qué cambió, cuándo, por qué y con qué
 * firma.
 *
 * ── Es un expediente, no un listado ──────────────────────────────────────
 *
 * De esto depende un despido con justa causa. Una amonestación sin fecha ni
 * firma no sirve en un juicio, y un cambio de salario sin el valor anterior no
 * se puede auditar. Por eso las columnas de valor anterior y nuevo van
 * siempre: son las que convierten «cambio de salario» en un hecho verificable.
 *
 * ── Las que no cambian la ficha también van ──────────────────────────────
 *
 * Una amonestación y unas vacaciones aprobadas no modifican ningún campo del
 * trabajador: son hechos que se registran. Que estén en la misma bitácora que
 * los cambios es el punto — el expediente de una persona es uno solo, y
 * repartirlo en dos listas es lo que hace que al armar un caso siempre falte
 * la mitad.
 */
class PersonnelActionsReport implements PayrollReport
{
    public function code(): string
    {
        return 'personnel-actions';
    }

    public function label(): string
    {
        return 'Acciones de personal';
    }

    public function description(): string
    {
        return 'Cambios de puesto y salario, amonestaciones, suspensiones y terminaciones, con su firma.';
    }

    public function decision(): string
    {
        return 'Qué le ha pasado a una persona en su historia laboral, y qué acciones están sin aplicar.';
    }

    public function group(): string
    {
        return 'Personal';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('from', 'Desde', ReportFilter::DATE, default: 'first_day_of_year',
                hint: 'Por fecha de vigencia de la acción.'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE),
            new ReportFilter('employee_id', 'Trabajador', ReportFilter::SELECT, optionSource: 'employees',
                hint: 'Con un trabajador escogido sale su expediente completo.'),
            new ReportFilter('action_type', 'Tipo de acción', ReportFilter::SELECT,
                options: PersonnelAction::TYPES),
            new ReportFilter('status', 'Estado', ReportFilter::SELECT, options: PersonnelAction::STATUSES),
            new ReportFilter('pending_only', 'Solo pendientes de aplicar', ReportFilter::BOOLEAN,
                hint: 'Borradores y aprobadas que todavía no tocaron la ficha.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $columns = [
            new ReportColumn('employee_code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('effective_date', 'Vigencia', ReportColumn::DATE),
            new ReportColumn('action_type', 'Acción'),
            new ReportColumn('field', 'Campo'),
            new ReportColumn('previous_value', 'Valor anterior'),
            new ReportColumn('new_value', 'Valor nuevo'),
            new ReportColumn('reason', 'Motivo'),
            new ReportColumn('status', 'Estado'),
            new ReportColumn('requested_by', 'Registrada por'),
            new ReportColumn('approved_by', 'Aprobada por'),
            new ReportColumn('approved_at', 'Aprobada el', ReportColumn::DATE),
            new ReportColumn('applied_at', 'Aplicada el', ReportColumn::DATE),
            new ReportColumn('notes', 'Observaciones'),
        ];

        $query = PersonnelAction::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->with([
                'employee:id,code,first_name,last_name1,last_name2',
                'requestedBy:id,name',
                'approvedBy:id,name',
            ])
            ->orderBy('effective_date')->orderBy('id');

        if (($filters['from'] ?? null) !== null) {
            $query->where('effective_date', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('effective_date', '<=', $filters['to']);
        }

        foreach (['employee_id', 'action_type', 'status'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                $query->where($key, $filters[$key]);
            }
        }

        if ($filters['pending_only'] ?? false) {
            $query->whereIn('status', ['draft', 'approved'])->whereNull('applied_at');
        }

        $rows = $query->get()->map(fn (PersonnelAction $a) => [
            'employee_code' => $a->employee?->code,
            'employee' => $a->employee?->fullName(),
            'effective_date' => $a->effective_date->format('Y-m-d'),
            'action_type' => PersonnelAction::TYPES[$a->action_type] ?? $a->action_type,
            'field' => $a->field,
            'previous_value' => $a->previous_value,
            'new_value' => $a->new_value,
            'reason' => $a->reason,
            'status' => PersonnelAction::STATUSES[$a->status] ?? $a->status,
            'requested_by' => $a->requestedBy?->name,
            'approved_by' => $a->approvedBy?->name,
            'approved_at' => $a->approved_at?->format('Y-m-d'),
            'applied_at' => $a->applied_at?->format('Y-m-d'),
            'notes' => $a->notes,
        ])->all();

        $notes = [
            'El valor anterior y el nuevo son lo que convierte «cambio de salario» en un hecho auditable: '.
            'una acción sin ellos no se puede comprobar.',
        ];

        $recordOnly = implode(', ', array_map(
            fn (string $type) => mb_strtolower(PersonnelAction::TYPES[$type] ?? $type),
            PersonnelAction::RECORD_ONLY
        ));

        $notes[] = "Las acciones de {$recordOnly} no cambian ningún campo de la ficha: son hechos que se ".
            'registran, y por eso salen sin valor anterior ni nuevo.';

        return new ReportResult($columns, $rows, notes: $notes);
    }
}
