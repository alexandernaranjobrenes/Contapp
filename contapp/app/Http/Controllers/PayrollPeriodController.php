<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollInput;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Services\CalculatePayrollService;
use App\Domains\Payroll\Services\PayrollReadinessChecker;
use App\Domains\Payroll\Services\PostPayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayrollPeriodController extends Controller
{
    public function __construct(
        private readonly CalculatePayrollService $calculator,
        private readonly PostPayrollService $poster,
        private readonly PayrollReadinessChecker $readiness,
    ) {}

    public function index(): Response
    {
        $periods = PayrollPeriod::withCount('entries')
            ->orderByDesc('year')->orderByDesc('start_date')->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('Payroll/Periods/Index', [
            'periods' => $periods->map(fn (PayrollPeriod $p) => [
                'id' => $p->id,
                'year' => $p->year,
                'frequency' => $p->frequency,
                'frequency_label' => PayrollPeriod::FREQUENCIES[$p->frequency] ?? $p->frequency,
                'number' => $p->number,
                'name' => $p->name,
                'start_date' => $p->start_date->format('Y-m-d'),
                'end_date' => $p->end_date->format('Y-m-d'),
                'payment_date' => $p->payment_date->format('Y-m-d'),
                'status' => $p->status,
                'status_label' => PayrollPeriod::STATUSES[$p->status] ?? $p->status,
                'entries_count' => $p->entries_count,
                'journal_entry_id' => $p->journal_entry_id,
            ])->values(),
            'frequencies' => PayrollPeriod::FREQUENCIES,
            'statuses' => PayrollPeriod::STATUSES,
        ]);
    }

    /**
     * La planilla del período: una fila por trabajador, con el desglose.
     *
     * Se muestran también los totales patronales, que no rebajan a nadie
     * pero son la mitad del costo real y casi nunca se ven juntos.
     */
    public function show(int $payrollPeriod): Response
    {
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        $entries = PayrollEntry::with(['employee:id,code,first_name,last_name1,last_name2', 'costCenter:id,code', 'lines'])
            ->where('payroll_period_id', $period->id)
            ->get()
            ->sortBy(fn (PayrollEntry $e) => $e->employee?->code)
            ->values();

        return Inertia::render('Payroll/Periods/Show', [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'year' => $period->year,
                'frequency' => $period->frequency,
                'frequency_label' => PayrollPeriod::FREQUENCIES[$period->frequency] ?? $period->frequency,
                'start_date' => $period->start_date->format('Y-m-d'),
                'end_date' => $period->end_date->format('Y-m-d'),
                'payment_date' => $period->payment_date->format('Y-m-d'),
                'status' => $period->status,
                'status_label' => PayrollPeriod::STATUSES[$period->status] ?? $period->status,
                'is_recalculable' => $period->isRecalculable(),
                'journal_entry_id' => $period->journal_entry_id,
                'calculated_at' => $period->calculated_at?->format('Y-m-d H:i'),
                'approved_at' => $period->approved_at?->format('Y-m-d H:i'),
            ],
            'entries' => $entries->map(fn (PayrollEntry $e) => [
                'id' => $e->id,
                'employee_id' => $e->employee_id,
                'employee_code' => $e->employee?->code,
                'employee_name' => $e->employee?->fullName(),
                'cost_center' => $e->costCenter?->code,
                'days_worked' => (float) $e->days_worked,
                'total_earnings' => $e->total_earnings,
                'ccss_base' => $e->ccss_base,
                'total_employee_contributions' => $e->total_employee_contributions,
                'income_tax' => $e->income_tax,
                'total_other_deductions' => $e->total_other_deductions,
                'total_deductions' => $e->total_deductions,
                'net_pay' => $e->net_pay,
                'total_employer_contributions' => $e->total_employer_contributions,
                'total_provisions' => $e->total_provisions,
                'employer_cost' => $e->employerCost(),
                'payment_method' => $e->payment_method,
                // La pantalla avisa de quién cobra por transferencia y no
                // tiene cuenta: es más barato verlo acá que cuando esa
                // persona llame a decir que no le llegó el salario.
                'bank_account' => $e->bank_account,
            ])->values(),
            'totals' => $this->totals($entries),
            // La lista de verificación se calcula en cada visita y no se
            // guarda: su respuesta depende de la configuración de HOY, y una
            // guardada mentiría en cuanto alguien corrigiera una ficha.
            'readiness' => $period->isRecalculable()
                ? $this->readiness->check(Company::findOrFail($period->company_id), $period)
                : null,
            // Lo que se le puede digitar a un trabajador: horas extra, un
            // bono, un rebajo puntual. El salario y las cargas no están acá
            // a propósito — el primero viene de la ficha, las segundas las
            // calcula el motor.
            'concepts' => PayrollConcept::where('status', 'active')
                ->orderBy('type')->orderBy('code')
                ->get(['id', 'code', 'name', 'type', 'calculation', 'factor'])
                ->map(fn (PayrollConcept $c) => [
                    ...$c->only(['id', 'code', 'name', 'type', 'calculation']),
                    'factor' => $c->factor === null ? null : (float) $c->factor,
                ]),
            'employees' => Employee::whereIn('status', ['active', 'suspended'])
                ->orderBy('code')
                ->get(['id', 'code', 'first_name', 'last_name1', 'last_name2'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id, 'code' => $e->code, 'name' => $e->fullName(),
                ]),
            'inputs' => PayrollInput::with(['employee:id,code', 'concept:id,code,name,type,calculation'])
                ->where('payroll_period_id', $period->id)
                ->orderBy('employee_id')->orderBy('id')
                ->get()
                ->map(fn (PayrollInput $i) => [
                    'id' => $i->id,
                    'employee_id' => $i->employee_id,
                    'employee_code' => $i->employee?->code,
                    'payroll_concept_id' => $i->payroll_concept_id,
                    'concept_code' => $i->concept?->code,
                    'concept_name' => $i->concept?->name,
                    'concept_calculation' => $i->concept?->calculation,
                    'amount' => $i->amount,
                    'quantity' => $i->quantity === null ? null : (float) $i->quantity,
                    'notes' => $i->notes,
                ])->values(),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'frequency' => ['required', Rule::in(array_keys(PayrollPeriod::FREQUENCIES))],
            'number' => ['required', 'integer', 'min:1', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            // La fecha de pago puede caer después del cierre del período —
            // una quincena que cierra el 30 y se paga el 2 es lo normal—,
            // pero nunca antes: no se paga lo que todavía no se devengó.
            'payment_date' => ['required', 'date', 'after_or_equal:end_date'],
        ]);

        $duplicate = PayrollPeriod::where('year', $validated['year'])
            ->where('frequency', $validated['frequency'])
            ->where('number', $validated['number'])
            ->exists();

        if ($duplicate) {
            return back()->withErrors([
                'number' => "Ya existe el período {$validated['number']} de {$validated['year']} con esa frecuencia.",
            ])->withInput();
        }

        // Dos períodos de la misma frecuencia que se traslapan pagarían dos
        // veces los mismos días. Se revisa acá porque el motor, que trabaja
        // un período a la vez, no tiene forma de verlo.
        $overlap = PayrollPeriod::where('frequency', $validated['frequency'])
            ->where('start_date', '<=', $validated['end_date'])
            ->where('end_date', '>=', $validated['start_date'])
            ->first();

        if ($overlap !== null) {
            return back()->withErrors([
                'start_date' => "Estas fechas se traslapan con el período «{$overlap->name}» ".
                    "({$overlap->start_date->format('Y-m-d')} a {$overlap->end_date->format('Y-m-d')}).",
            ])->withInput();
        }

        PayrollPeriod::create([
            ...$validated,
            'company_id' => $companyId,
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Período de planilla creado.');
    }

    /**
     * Registra un movimiento digitado del período: horas extra, un bono, un
     * rebajo puntual.
     */
    public function storeInput(Request $request, int $payrollPeriod, CurrentCompany $currentCompany): RedirectResponse
    {
        $period = PayrollPeriod::findOrFail($payrollPeriod);
        $companyId = $currentCompany->id();

        if (! $period->isRecalculable()) {
            return back()->withErrors([
                'payroll' => "El período «{$period->name}» ya está ".
                    mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status).
                    ': no admite movimientos nuevos.',
            ]);
        }

        $validated = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'payroll_concept_id' => ['required', Rule::exists('payroll_concepts', 'id')->where('company_id', $companyId)],
            'amount' => ['nullable', 'numeric'],
            'quantity' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $concept = PayrollConcept::findOrFail($validated['payroll_concept_id']);

        // Un concepto por horas sin horas, o uno por monto sin monto, se
        // guardaría en cero y desaparecería del cálculo sin decir nada. Es
        // mejor rechazarlo acá que dejar que alguien lo descubra revisando
        // por qué a un trabajador no se le pagaron sus extras.
        if ($concept->calculation === 'hours' && ($validated['quantity'] ?? null) === null) {
            return back()->withErrors([
                'quantity' => "El concepto {$concept->code} se paga por horas: indicá la cantidad.",
            ])->withInput();
        }

        if ($concept->calculation === 'amount' && ($validated['amount'] ?? null) === null) {
            return back()->withErrors([
                'amount' => "El concepto {$concept->code} se digita por monto: indicá el importe.",
            ])->withInput();
        }

        PayrollInput::create([
            ...$validated,
            'company_id' => $companyId,
            'payroll_period_id' => $period->id,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Movimiento registrado. Recalculá la planilla para que se aplique.');
    }

    public function destroyInput(int $payrollPeriod, int $input): RedirectResponse
    {
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        if (! $period->isRecalculable()) {
            return back()->withErrors([
                'payroll' => "El período «{$period->name}» ya no admite cambios en sus movimientos.",
            ]);
        }

        PayrollInput::where('payroll_period_id', $period->id)->findOrFail($input)->delete();

        return back()->with('success', 'Movimiento eliminado. Recalculá la planilla.');
    }

    /**
     * Corre el motor sobre los movimientos ya registrados del período.
     *
     * Leerlos de la tabla y no del formulario es lo que hace del recálculo
     * un botón: corregir un dato no obliga a volver a digitar los demás.
     */
    public function calculate(int $payrollPeriod, CurrentCompany $currentCompany): RedirectResponse
    {
        $period = PayrollPeriod::findOrFail($payrollPeriod);
        $company = $this->company($currentCompany->id());

        // Se revisa la configuración ANTES de calcular. Un empleado sin
        // salario no hace fallar el cálculo: produce una boleta en cero que se
        // pierde entre cincuenta. Por eso los hallazgos de severidad 'error'
        // bloquean, y las advertencias no.
        $readiness = $this->readiness->check($company, $period);

        if (! $readiness['ok']) {
            $titles = collect($readiness['findings'])
                ->where('severity', PayrollReadinessChecker::ERROR)
                ->pluck('title')
                ->take(4)
                ->implode('; ');

            $extra = $readiness['errors'] > 4 ? " (y {$readiness['errors']} en total)" : '';

            return back()->withErrors([
                'payroll' => "No se puede calcular todavía: {$titles}{$extra}. ".
                    'Revisá la lista de verificación del período.',
            ]);
        }

        $inputs = PayrollInput::where('payroll_period_id', $period->id)
            ->get()
            ->map(fn (PayrollInput $i) => $i->toInputLine())
            ->all();

        try {
            $this->calculator->calculate($company, $period, $inputs);
        } catch (InvalidPayrollException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        $note = $readiness['warnings'] > 0
            ? " Quedan {$readiness['warnings']} advertencia(s) sin resolver."
            : '';

        return back()->with('success', "Planilla calculada.{$note}");
    }

    public function approve(int $payrollPeriod, Request $request): RedirectResponse
    {
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        if ($period->status !== 'calculated') {
            return back()->withErrors([
                'payroll' => 'Solo se aprueba una planilla calculada. '.
                    'Esta está '.mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status).'.',
            ]);
        }

        $period->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Planilla aprobada.');
    }

    public function post(Request $request, int $payrollPeriod, CurrentCompany $currentCompany): RedirectResponse
    {
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        $validated = $request->validate([
            'posting_date' => ['nullable', 'date'],
        ]);

        try {
            $this->poster->post(
                $this->company($currentCompany->id()),
                $period,
                isset($validated['posting_date']) ? new \DateTimeImmutable($validated['posting_date']) : null,
                $request->user()->id,
            );
        } catch (InvalidPayrollException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('success', 'Planilla contabilizada.');
    }

    public function destroy(int $payrollPeriod): RedirectResponse
    {
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        // Un período contabilizado tiene un asiento detrás: borrarlo dejaría
        // la contabilidad respaldando una planilla que ya no existe.
        if (! $period->isRecalculable()) {
            return back()->withErrors([
                'payroll' => "El período «{$period->name}» ya está ".
                    mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status).
                    ' y no se puede eliminar.',
            ]);
        }

        // Borrar el período es descartar su cálculo, y eso alcanza más allá de
        // las boletas: hay que devolver el saldo de los préstamos que se
        // rebajaron y quitar los días de vacaciones que se acreditaron. Sin
        // esto, el trabajador queda debiendo menos de lo que debe y con días
        // que nadie le acreditó, y el período que los produjo ya no existe
        // para explicarlo.
        DB::transaction(function () use ($period) {
            $this->calculator->discardCalculation($period);

            $period->delete();
        });

        return back()->with('success', 'Período eliminado.');
    }

    /**
     * Los totales de la planilla, incluido el costo patronal.
     *
     * @return array<string, string>
     */
    private function totals($entries): array
    {
        $sum = fn (string $field) => $entries->reduce(
            fn ($carry, PayrollEntry $e) => bcadd($carry, (string) $e->{$field}, 2), '0.00'
        );

        $earnings = $sum('total_earnings');
        $employer = $sum('total_employer_contributions');
        $provisions = $sum('total_provisions');

        return [
            'employees' => (string) $entries->count(),
            'total_earnings' => $earnings,
            'ccss_base' => $sum('ccss_base'),
            'total_employee_contributions' => $sum('total_employee_contributions'),
            'income_tax' => $sum('income_tax'),
            'total_other_deductions' => $sum('total_other_deductions'),
            'total_deductions' => $sum('total_deductions'),
            'net_pay' => $sum('net_pay'),
            'total_employer_contributions' => $employer,
            'total_provisions' => $provisions,
            // Lo que de verdad le cuesta la planilla a la empresa. Es el
            // número que no aparece en ningún lado y el único que sirve para
            // presupuestar.
            'employer_cost' => bcadd(bcadd($earnings, $employer, 2), $provisions, 2),
        ];
    }

    private function company(int $companyId): Company
    {
        return Company::findOrFail($companyId);
    }
}
