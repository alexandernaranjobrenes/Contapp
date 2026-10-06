<?php

namespace App\Domains\Conti\Resources;

use App\Domains\Conti\Support\ContiContext;
use App\Domains\Conti\Support\ContiFormat as F;
use App\Domains\Conti\Support\ContiRedactor;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\EmployeeNote;
use App\Domains\Payroll\Models\EmployeeRecurringInput;
use App\Domains\Payroll\Models\JobPosition;
use App\Domains\Payroll\Models\LaborSettlement;
use App\Domains\Payroll\Models\LaborSettlementLine;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollEntryLine;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollTaxBracket;
use App\Domains\Payroll\Models\PayrollTaxCredit;
use App\Domains\Payroll\Models\PersonnelAction;
use App\Domains\Payroll\Models\VacationMovement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Planillas. De cada trabajador, Conti ve lo laboral (puesto, jornada,
 * salario, saldos) y no lo personal: ni identificación, ni número de
 * asegurado, ni fecha de nacimiento, ni correo, teléfono o dirección. La
 * cuenta bancaria, con sus últimos cuatro dígitos. Las anotaciones
 * confidenciales, nunca.
 */
final class PayrollResources
{
    private const PAYROLL_SCREENS = [
        'payroll.employees', 'payroll.personnel_actions', 'payroll.vacations', 'payroll.labor_settlements',
        'payroll.periods', 'payroll.recurring_inputs', 'payroll.deductions', 'payroll.reports',
    ];

    /** @return list<ContiResource> */
    public static function all(): array
    {
        return [
            self::employees(),
            self::notes(),
            self::personnelActions(),
            self::vacations(),
            self::settlements(),
            self::periods(),
            self::payslips(),
            self::recurringInputs(),
            self::deductions(),
            self::departments(),
            self::positions(),
            self::concepts(),
            self::contributions(),
            self::provisions(),
            self::taxBrackets(),
            self::taxCredits(),
        ];
    }

    /**
     * El salario y las condiciones de pago: con Empleados, Períodos de
     * planilla o Reportes de planilla, que ya los muestran. Quien solo
     * registra vacaciones o acciones de personal ve el resto de la ficha.
     */
    private static function seesSalary(): bool
    {
        return app(ContiContext::class)->allowsAny(['payroll.employees', 'payroll.periods', 'payroll.reports']);
    }

    private static function employeeName(?Employee $e): ?string
    {
        return $e ? trim($e->code.' '.$e->fullName()) : null;
    }

    private static function employees(): ContiResource
    {
        $row = function (Employee $e) {
            $salary = self::seesSalary();

            return [
                'id' => $e->id,
                'codigo' => $e->code,
                'nombre' => $e->fullName(),
                'puesto' => $e->jobPosition?->name ?? $e->position,
                'departamento' => $e->departmentRecord?->name ?? $e->department,
                'centro_costo' => F::codeName($e->costCenter),
                'ingreso' => F::date($e->hire_date),
                'salida' => F::date($e->termination_date),
                'motivo_salida' => $e->termination_reason,
                'contrato' => $e->contract_type,
                'jornada' => $e->journey_type,
                'horas_semanales' => F::number($e->weekly_hours, 2),
                'tipo_salario' => $e->salary_type,
                'salario_base' => $salary ? F::money($e->base_salary) : null,
                'divisor_dia' => $e->weekly_salary_divisor,
                'forma_pago' => $salary ? $e->payment_method : null,
                'banco' => $salary ? $e->bank_name : null,
                'cuenta_bancaria' => $salary ? ContiRedactor::mask($e->bank_account) : null,
                'hijos_con_credito' => $e->children_credit_count,
                'credito_conyuge' => F::yesNo($e->has_spouse_credit),
                'exento_impuesto' => F::yesNo($e->is_income_tax_exempt),
                'exento_cargas' => F::yesNo($e->is_ccss_exempt),
                'pensionado' => F::yesNo($e->is_pensioner),
                'estado' => F::status($e->status),
            ];
        };

        return new ContiResource(
            key: 'empleados',
            label: 'Empleados',
            description: 'Los trabajadores con sus datos laborales: puesto, departamento, jornada, salario y estado. Sin datos personales. El detalle suma el saldo de vacaciones y las deducciones activas.',
            screens: self::PAYROLL_SCREENS,
            query: fn () => Employee::query()->with(['jobPosition:id,name', 'departmentRecord:id,name', 'costCenter:id,code,name']),
            row: $row,
            detail: fn (Employee $e) => $row($e) + [
                'saldo_vacaciones_dias' => F::number($e->vacationBalance(), 2),
                'deducciones_activas' => $e->deductions()->where('status', 'active')->count(),
            ],
            search: fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w->where('code', $term)
                ->orWhere('first_name', 'like', "%{$term}%")->orWhere('last_name1', 'like', "%{$term}%")->orWhere('last_name2', 'like', "%{$term}%")),
            filters: [
                'estado' => ['active (activo), suspended (suspendido), terminated (de baja)', ContiFilters::equals('status')],
                'departamento' => ['código del departamento', ContiFilters::relatedCode('departmentRecord')],
                'centro_costo' => ['código del centro de costo', ContiFilters::relatedCode('costCenter')],
            ],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function notes(): ContiResource
    {
        return new ContiResource(
            key: 'anotaciones-empleado',
            label: 'Anotaciones de empleados (bitácora)',
            description: 'Hechos, observaciones, reconocimientos y llamadas de atención anotados a cada trabajador. Las confidenciales no se muestran.',
            screens: ['payroll.employees'],
            query: fn () => EmployeeNote::query()->where('is_confidential', false)->with(['employee:id,code,first_name,last_name1,last_name2', 'createdBy:id,name']),
            row: fn (EmployeeNote $n) => [
                'id' => $n->id,
                'fecha' => F::date($n->happened_on),
                'empleado' => self::employeeName($n->employee),
                'categoria' => EmployeeNote::CATEGORIES[$n->category] ?? $n->category,
                'titulo' => $n->title,
                'detalle' => $n->body,
                'anotado_por' => $n->createdBy?->name,
            ],
            search: ['title', 'body'],
            filters: ['empleado' => ['código del empleado', ContiFilters::relatedCode('employee')]],
            dateColumn: 'happened_on',
            orderBy: ['happened_on' => 'desc'],
            codeColumn: null,
        );
    }

    private static function personnelActions(): ContiResource
    {
        return new ContiResource(
            key: 'acciones-personal',
            label: 'Acciones de personal',
            description: 'Aumentos, cambios de puesto o jornada, amonestaciones, suspensiones y salidas, con su vigencia y quién las aprobó.',
            screens: ['payroll.personnel_actions', 'payroll.employees'],
            query: fn () => PersonnelAction::query()->with(['employee:id,code,first_name,last_name1,last_name2', 'requestedBy:id,name', 'approvedBy:id,name']),
            row: fn (PersonnelAction $a) => [
                'id' => $a->id,
                'empleado' => self::employeeName($a->employee),
                'accion' => PersonnelAction::TYPES[$a->action_type] ?? $a->action_type,
                'rige_desde' => F::date($a->effective_date),
                'campo' => $a->field,
                'antes' => $a->previous_value,
                'despues' => $a->new_value,
                'motivo' => $a->reason,
                'estado' => PersonnelAction::STATUSES[$a->status] ?? $a->status,
                'registrada_por' => $a->requestedBy?->name,
                'aprobada_por' => $a->approvedBy?->name,
            ],
            filters: [
                'empleado' => ['código del empleado', ContiFilters::relatedCode('employee')],
                'accion' => [ContiFilters::options(PersonnelAction::TYPES), ContiFilters::oneOf('action_type', PersonnelAction::TYPES)],
                'estado' => [ContiFilters::options(PersonnelAction::STATUSES), ContiFilters::oneOf('status', PersonnelAction::STATUSES)],
            ],
            dateColumn: 'effective_date',
            orderBy: ['effective_date' => 'desc'],
            codeColumn: null,
        );
    }

    private static function vacations(): ContiResource
    {
        return new ContiResource(
            key: 'vacaciones',
            label: 'Movimientos de vacaciones',
            description: 'Acreditaciones, disfrutes, pagos y ajustes de vacaciones. El saldo de cada trabajador es la suma de sus movimientos (ver el detalle de «empleados» o el reporte de saldo colectivo).',
            screens: ['payroll.vacations', 'payroll.employees'],
            query: fn () => VacationMovement::query()->with('employee:id,code,first_name,last_name1,last_name2'),
            row: fn (VacationMovement $m) => [
                'id' => $m->id,
                'fecha' => F::date($m->movement_date),
                'empleado' => self::employeeName($m->employee),
                'tipo' => VacationMovement::TYPES[$m->type] ?? $m->type,
                'dias' => F::number($m->days, 2),
                'desde' => F::date($m->from_date),
                'hasta' => F::date($m->to_date),
                'monto' => F::money($m->amount),
                'notas' => $m->notes,
            ],
            filters: [
                'empleado' => ['código del empleado', ContiFilters::relatedCode('employee')],
                'tipo' => [ContiFilters::options(VacationMovement::TYPES), ContiFilters::oneOf('type', VacationMovement::TYPES)],
            ],
            dateColumn: 'movement_date',
            orderBy: ['movement_date' => 'desc', 'id' => 'desc'],
            codeColumn: null,
        );
    }

    private static function settlements(): ContiResource
    {
        $row = fn (LaborSettlement $s) => [
            'id' => $s->id,
            'empleado' => self::employeeName($s->employee),
            'salida' => F::date($s->termination_date),
            'causal' => LaborSettlement::REASONS[$s->reason] ?? $s->reason,
            'anios_servicio' => F::number($s->years_of_service, 2),
            'salario_promedio_mensual' => F::money($s->average_monthly_salary),
            'bruto' => F::money($s->total_gross),
            'cargas' => F::money($s->total_ccss),
            'impuesto' => F::money($s->total_income_tax),
            'otras_deducciones' => F::money($s->total_other_deductions),
            'neto' => F::money($s->total_net),
            'estado' => LaborSettlement::STATUSES[$s->status] ?? $s->status,
        ];

        return new ContiResource(
            key: 'liquidaciones',
            label: 'Liquidaciones laborales',
            description: 'Las liquidaciones por salida de un trabajador. El detalle trae cada extremo: aguinaldo, vacaciones, preaviso, cesantía.',
            screens: ['payroll.labor_settlements'],
            query: fn () => LaborSettlement::query()->with('employee:id,code,first_name,last_name1,last_name2'),
            row: $row,
            detail: fn (LaborSettlement $s) => $row($s) + [
                'detalle_causal' => $s->reason_detail,
                'extremos' => $s->lines()->orderBy('line_number')->get()->map(fn (LaborSettlementLine $l) => [
                    'extremo' => LaborSettlementLine::KINDS[$l->kind] ?? $l->name,
                    'nombre' => $l->name,
                    'dias' => F::number($l->days, 2),
                    'valor_dia' => F::money($l->daily_rate),
                    'monto' => F::money($l->amount),
                ])->all(),
            ],
            filters: [
                'empleado' => ['código del empleado', ContiFilters::relatedCode('employee')],
                'estado' => [ContiFilters::options(LaborSettlement::STATUSES), ContiFilters::oneOf('status', LaborSettlement::STATUSES)],
            ],
            dateColumn: 'termination_date',
            orderBy: ['termination_date' => 'desc'],
            codeColumn: null,
        );
    }

    private static function periods(): ContiResource
    {
        return new ContiResource(
            key: 'periodos-planilla',
            label: 'Períodos de planilla',
            description: 'Cada planilla (quincena, mes o semana) con su estado y sus totales: bruto, neto y costo para la empresa.',
            screens: ['payroll.periods', 'payroll.reports'],
            query: fn () => PayrollPeriod::query()
                ->withCount('entries')
                ->withSum('entries', 'total_earnings')
                ->withSum('entries', 'net_pay')
                ->withSum('entries', 'total_employer_contributions')
                ->withSum('entries', 'total_provisions'),
            row: fn (PayrollPeriod $p) => [
                'id' => $p->id,
                'nombre' => $p->name,
                'anio' => $p->year,
                'frecuencia' => PayrollPeriod::FREQUENCIES[$p->frequency] ?? $p->frequency,
                'numero' => $p->number,
                'desde' => F::date($p->start_date),
                'hasta' => F::date($p->end_date),
                'fecha_pago' => F::date($p->payment_date),
                'estado' => PayrollPeriod::STATUSES[$p->status] ?? $p->status,
                'boletas' => $p->entries_count,
                'total_bruto' => F::money($p->entries_sum_total_earnings ?? 0),
                'total_neto' => F::money($p->entries_sum_net_pay ?? 0),
                'cargas_patronales' => F::money($p->entries_sum_total_employer_contributions ?? 0),
                'provisiones' => F::money($p->entries_sum_total_provisions ?? 0),
            ],
            search: ['name'],
            filters: [
                'anio' => ['año, ej. 2026', ContiFilters::equals('year')],
                'estado' => [ContiFilters::options(PayrollPeriod::STATUSES), ContiFilters::oneOf('status', PayrollPeriod::STATUSES)],
                'frecuencia' => [ContiFilters::options(PayrollPeriod::FREQUENCIES), ContiFilters::oneOf('frequency', PayrollPeriod::FREQUENCIES)],
            ],
            dateColumn: 'payment_date',
            orderBy: ['end_date' => 'desc', 'id' => 'desc'],
            codeColumn: null,
        );
    }

    private static function payslips(): ContiResource
    {
        $row = fn (PayrollEntry $e) => [
            'id' => $e->id,
            'periodo' => $e->period?->name,
            'empleado' => self::employeeName($e->employee),
            'dias' => F::number($e->days_worked, 2),
            'salario_base' => F::money($e->base_salary),
            'devengado' => F::money($e->total_earnings),
            'cargas_obreras' => F::money($e->total_employee_contributions),
            'impuesto_salario' => F::money($e->income_tax),
            'otras_deducciones' => F::money($e->total_other_deductions),
            'neto' => F::money($e->net_pay),
            'cargas_patronales' => F::money($e->total_employer_contributions),
            'provisiones' => F::money($e->total_provisions),
            'forma_pago' => $e->payment_method,
        ];

        return new ContiResource(
            key: 'boletas',
            label: 'Boletas de pago',
            description: 'La boleta de cada trabajador en cada planilla, del bruto al neto. El detalle trae cada línea con su base, tasa y monto.',
            screens: ['payroll.periods'],
            query: fn () => PayrollEntry::query()->whereHas('period')->with([
                'period:id,name,end_date', 'employee:id,code,first_name,last_name1,last_name2',
            ]),
            row: $row,
            detail: fn (PayrollEntry $e) => $row($e) + [
                'lineas' => $e->lines()->orderBy('line_number')->get()->map(fn (PayrollEntryLine $l) => [
                    'tipo' => PayrollEntryLine::KINDS[$l->kind] ?? $l->kind,
                    'concepto' => trim(($l->code ?? '').' '.($l->name ?? '')),
                    'base' => F::money($l->base_amount),
                    'tasa' => F::number($l->rate, 4),
                    'cantidad' => F::number($l->quantity, 2),
                    'monto' => F::money($l->amount),
                ])->all(),
            ],
            filters: [
                'periodo' => ['id del período de planilla', ContiFilters::equals('payroll_period_id')],
                'empleado' => ['código del empleado', ContiFilters::relatedCode('employee')],
            ],
            orderBy: ['payroll_period_id' => 'desc', 'id' => 'asc'],
            codeColumn: null,
        );
    }

    private static function recurringInputs(): ContiResource
    {
        return new ContiResource(
            key: 'rubros-fijos',
            label: 'Rubros fijos',
            description: 'Montos u horas que se repiten cada planilla a un trabajador. Si en un período se digita el mismo rubro, lo digitado reemplaza al fijo.',
            screens: ['payroll.recurring_inputs'],
            query: fn () => EmployeeRecurringInput::query()->with(['employee:id,code,first_name,last_name1,last_name2', 'concept:id,code,name']),
            row: fn (EmployeeRecurringInput $r) => [
                'id' => $r->id,
                'empleado' => self::employeeName($r->employee),
                'rubro' => F::codeName($r->concept),
                'monto' => F::money($r->amount),
                'horas' => F::number($r->quantity, 2),
                'vigente_desde' => F::date($r->start_date),
                'vigente_hasta' => F::date($r->end_date),
                'estado' => F::status($r->status),
                'notas' => $r->notes,
            ],
            filters: [
                'empleado' => ['código del empleado', ContiFilters::relatedCode('employee')],
                'estado' => ['active | suspended', ContiFilters::equals('status')],
            ],
            orderBy: ['employee_id' => 'asc'],
            codeColumn: null,
        );
    }

    private static function deductions(): ContiResource
    {
        return new ContiResource(
            key: 'deducciones',
            label: 'Deducciones y préstamos',
            description: 'Adelantos, préstamos, cuotas solidaristas, pensiones y embargos que se rebajan en cada planilla, con su saldo.',
            screens: ['payroll.deductions'],
            query: fn () => EmployeeDeduction::query()->with('employee:id,code,first_name,last_name1,last_name2'),
            row: fn (EmployeeDeduction $d) => [
                'id' => $d->id,
                'empleado' => self::employeeName($d->employee),
                'tipo' => EmployeeDeduction::TYPES[$d->type] ?? $d->type,
                'descripcion' => $d->description,
                'referencia' => $d->reference,
                'prioridad' => $d->priority,
                'monto_otorgado' => F::money($d->original_amount),
                'saldo' => F::money($d->balance),
                'calculo' => $d->calculation,
                'cuota' => F::money($d->installment_amount),
                'porcentaje' => F::number($d->installment_percentage, 2),
                'desde' => F::date($d->start_date),
                'hasta' => F::date($d->end_date),
                'estado' => F::status($d->status),
            ],
            search: ['description', 'reference'],
            filters: [
                'empleado' => ['código del empleado', ContiFilters::relatedCode('employee')],
                'tipo' => [ContiFilters::options(EmployeeDeduction::TYPES), ContiFilters::oneOf('type', EmployeeDeduction::TYPES)],
                'estado' => ['active | suspended | settled | cancelled', ContiFilters::equals('status')],
            ],
            orderBy: ['employee_id' => 'asc', 'priority' => 'asc'],
            codeColumn: null,
        );
    }

    private static function departments(): ContiResource
    {
        return new ContiResource(
            key: 'departamentos',
            label: 'Departamentos',
            description: 'Los departamentos, su centro de costo y cuántos trabajadores tienen.',
            screens: ['payroll.job_structure', 'payroll.employees'],
            query: fn () => Department::query()->with('costCenter:id,code,name')->withCount('employees'),
            row: fn (Department $d) => [
                'id' => $d->id,
                'codigo' => $d->code,
                'nombre' => $d->name,
                'centro_costo' => F::codeName($d->costCenter),
                'trabajadores' => $d->employees_count,
                'estado' => F::status($d->status),
            ],
            search: ['code', 'name'],
            orderBy: ['name' => 'asc'],
        );
    }

    private static function positions(): ContiResource
    {
        return new ContiResource(
            key: 'puestos',
            label: 'Puestos',
            description: 'Los puestos, su departamento, la ocupación CCSS y el rango salarial de referencia.',
            screens: ['payroll.job_structure', 'payroll.employees'],
            query: fn () => JobPosition::query()->with('department:id,name')->withCount('employees'),
            row: fn (JobPosition $p) => [
                'id' => $p->id,
                'codigo' => $p->code,
                'nombre' => $p->name,
                'departamento' => $p->department?->name,
                'ocupacion_ccss' => trim(($p->ccss_occupation_code ?? '').' '.($p->ccss_occupation_name ?? '')) ?: null,
                'salario_minimo_referencia' => F::money($p->min_salary),
                'salario_maximo_referencia' => F::money($p->max_salary),
                'trabajadores' => $p->employees_count,
                'estado' => F::status($p->status),
            ],
            search: ['code', 'name'],
            orderBy: ['name' => 'asc'],
        );
    }

    private static function concepts(): ContiResource
    {
        return new ContiResource(
            key: 'conceptos-planilla',
            label: 'Conceptos de planilla',
            description: 'Los ingresos y deducciones que se usan en planilla, cómo se calculan y si forman salario para cargas, impuesto y provisiones.',
            screens: ['payroll.settings', 'payroll.periods', 'payroll.recurring_inputs'],
            query: fn () => PayrollConcept::query()->with('account:id,code,description_es'),
            row: fn (PayrollConcept $c) => [
                'id' => $c->id,
                'codigo' => $c->code,
                'nombre' => $c->name,
                'tipo' => PayrollConcept::TYPES[$c->type] ?? $c->type,
                'calculo' => PayrollConcept::CALCULATIONS[$c->calculation] ?? $c->calculation,
                'factor' => F::number($c->factor, 4),
                'forma_salario_para_cargas' => F::yesNo($c->affects_ccss),
                'sujeto_impuesto_salario' => F::yesNo($c->affects_income_tax),
                'entra_a_provisiones' => F::yesNo($c->affects_provisions),
                'cuenta' => $c->account ? "{$c->account->code} {$c->account->description_es}" : null,
                'estado' => F::status($c->status),
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function contributions(): ContiResource
    {
        return new ContiResource(
            key: 'cargas-sociales',
            label: 'Cargas sociales',
            description: 'Los componentes de carga social (CCSS, Banco Popular, etc.), quién los paga y su porcentaje vigente.',
            screens: ['payroll.settings', 'payroll.reports'],
            query: fn () => PayrollContribution::query(),
            row: fn (PayrollContribution $c) => [
                'id' => $c->id,
                'codigo' => $c->code,
                'nombre' => $c->name,
                'paga' => $c->payer === 'employer' ? 'patrono' : ($c->payer === 'employee' ? 'trabajador' : $c->payer),
                'institucion' => $c->institution,
                'porcentaje' => F::number($c->percentage, 4),
                'base' => $c->base,
                'tope_salarial' => F::money($c->ceiling_amount),
                'pensionado_no_cotiza' => F::yesNo($c->exempt_for_pensioner),
                'vigente_desde' => F::date($c->valid_from),
                'vigente_hasta' => F::date($c->valid_to),
                'estado' => F::status($c->status),
            ],
            search: ['code', 'name', 'institution'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function provisions(): ContiResource
    {
        return new ContiResource(
            key: 'provisiones-planilla',
            label: 'Provisiones de planilla',
            description: 'Aguinaldo, vacaciones y cesantía que se provisionan cada planilla, con su porcentaje.',
            screens: ['payroll.settings', 'payroll.reports'],
            query: fn () => PayrollProvision::query(),
            row: fn (PayrollProvision $p) => [
                'id' => $p->id,
                'codigo' => $p->code,
                'nombre' => $p->name,
                'porcentaje' => F::number($p->percentage, 4),
                'vigente_desde' => F::date($p->valid_from),
                'vigente_hasta' => F::date($p->valid_to),
                'estado' => F::status($p->status),
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function taxBrackets(): ContiResource
    {
        return new ContiResource(
            key: 'tramos-impuesto-salario',
            label: 'Tramos del impuesto al salario',
            description: 'La escala mensual y progresiva del impuesto al salario configurada en la compañía.',
            screens: ['payroll.settings', 'payroll.reports'],
            query: fn () => PayrollTaxBracket::query(),
            row: fn (PayrollTaxBracket $b) => [
                'tramo' => $b->bracket_number,
                'desde' => F::money($b->from_amount),
                'hasta' => F::money($b->to_amount),
                'tasa' => F::number($b->percentage, 2),
                'vigente_desde' => F::date($b->valid_from),
                'vigente_hasta' => F::date($b->valid_to),
            ],
            orderBy: ['valid_from' => 'desc', 'bracket_number' => 'asc'],
            codeColumn: null,
        );
    }

    private static function taxCredits(): ContiResource
    {
        return new ContiResource(
            key: 'creditos-familiares',
            label: 'Créditos familiares del impuesto',
            description: 'Los montos mensuales de crédito por hijo y por cónyuge que se restan del impuesto al salario.',
            screens: ['payroll.settings', 'payroll.reports'],
            query: fn () => PayrollTaxCredit::query(),
            row: fn (PayrollTaxCredit $c) => [
                'codigo' => $c->code,
                'nombre' => $c->name,
                'monto_mensual' => F::money($c->monthly_amount),
                'vigente_desde' => F::date($c->valid_from),
                'vigente_hasta' => F::date($c->valid_to),
            ],
            orderBy: ['valid_from' => 'desc'],
        );
    }
}
