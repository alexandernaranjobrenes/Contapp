<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\LaborSettlement;
use App\Domains\Payroll\Models\LaborSettlementLine;
use App\Domains\Payroll\Services\CalculateSettlementService;
use App\Domains\Payroll\Services\PostSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Liquidaciones laborales.
 *
 * El ciclo es el mismo de la planilla y por la misma razón: borrador se
 * recalcula y se puede eliminar; aprobada se congela; contabilizada solo se
 * anula con reversión. Un documento que el trabajador firmó no se borra.
 */
class LaborSettlementController extends Controller
{
    public function __construct(
        private readonly CalculateSettlementService $calculator,
        private readonly PostSettlementService $poster,
    ) {}

    public function index(): Response
    {
        $settlements = LaborSettlement::with('employee:id,code,first_name,last_name1,last_name2')
            ->orderByDesc('termination_date')->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('Payroll/Settlements/Index', [
            'settlements' => $settlements->map(fn (LaborSettlement $s) => $this->row($s))->values(),
            // Solo los que siguen activos: liquidar a alguien ya liquidado es
            // el error que hay que hacer imposible desde la lista.
            'employees' => Employee::active()
                ->orderBy('code')
                ->get(['id', 'code', 'first_name', 'last_name1', 'last_name2', 'hire_date', 'base_salary', 'salary_type'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'code' => $e->code,
                    'full_name' => $e->fullName(),
                    'hire_date' => $e->hire_date->format('Y-m-d'),
                    'base_salary' => $e->base_salary,
                    'salary_type' => $e->salary_type,
                ])->values(),
            'reasons' => LaborSettlement::REASONS,
            'statuses' => LaborSettlement::STATUSES,
            'entitlements' => LaborSettlement::ENTITLEMENTS,
            'kinds' => LaborSettlementLine::KINDS,
        ]);
    }

    public function show(int $settlement, CurrentCompany $currentCompany): Response
    {
        $model = LaborSettlement::with(['lines', 'employee', 'journalEntry:id,entry_number'])
            ->findOrFail($settlement);

        return Inertia::render('Payroll/Settlements/Show', [
            'settlement' => $this->row($model) + [
                'reason_detail' => $model->reason_detail,
                'notes' => $model->notes,
                'average_monthly_salary' => $model->average_monthly_salary,
                'average_daily_salary' => $model->average_daily_salary,
                'vacation_daily_salary' => $model->vacation_daily_salary,
                'christmas_bonus_base' => $model->christmas_bonus_base,
                'years_of_service' => $model->years_of_service,
                'bases_from_history' => (bool) $model->bases_from_history,
                'history_months_found' => $model->history_months_found,
                'calculated_at' => $model->calculated_at?->format('Y-m-d H:i'),
                'journal_entry_number' => $model->journalEntry?->entry_number,
            ],
            'lines' => $model->lines->map(fn (LaborSettlementLine $l) => [
                'id' => $l->id,
                'kind' => $l->kind,
                'kind_label' => $l->kindLabel(),
                'code' => $l->code,
                'name' => $l->name,
                'detail' => $l->detail,
                'days' => $l->days,
                'daily_rate' => $l->daily_rate,
                'amount' => $l->amount,
                'subject_to_ccss' => (bool) $l->subject_to_ccss,
                'is_deduction' => $l->isDeduction(),
            ])->values(),
            // Las advertencias se calculan al abrir, no se guardan: dependen
            // del historial, y el historial cambia cuando se cargan planillas
            // viejas. Una advertencia congelada mentiría.
            'findings' => $model->isRecalculable() || $model->status === 'approved'
                ? $this->calculator->findings($this->company($currentCompany->id()), $model)
                : [],
            'reasons' => LaborSettlement::REASONS,
            'statuses' => LaborSettlement::STATUSES,
            'kinds' => LaborSettlementLine::KINDS,
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'termination_date' => ['required', 'date'],
            'reason' => ['required', Rule::in(array_keys(LaborSettlement::REASONS))],
            'reason_detail' => ['nullable', 'string', 'max:5000'],
        ]);

        // Una sola liquidación viva por trabajador: dos borradores del mismo
        // caso terminan contabilizándose los dos.
        $existing = LaborSettlement::where('employee_id', $validated['employee_id'])
            ->whereIn('status', ['draft', 'approved', 'posted'])
            ->first();

        if ($existing !== null) {
            return back()->withErrors(['employee_id' => 'Este trabajador ya tiene una liquidación '.
                mb_strtolower(LaborSettlement::STATUSES[$existing->status]).
                '. Hay que anularla o eliminarla antes de hacer otra.']);
        }

        $settlement = LaborSettlement::create($validated + [
            'company_id' => $companyId,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        try {
            $this->calculator->calculate($this->company($companyId), $settlement);
        } catch (InvalidPayrollException $e) {
            $settlement->delete();

            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return redirect()->route('labor-settlements.show', $settlement->id)
            ->with('success', 'Liquidación calculada. Hay que revisar las bases y las advertencias antes de aprobarla.');
    }

    /**
     * Recalcula agregando o reemplazando los renglones manuales.
     *
     * Los manuales van completos en cada llamada, no de a uno: así el
     * resultado de recalcular es siempre el mismo que calcular desde cero, que
     * es la misma regla del motor de planilla.
     */
    public function calculate(Request $request, int $settlement, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = LaborSettlement::findOrFail($settlement);

        $validated = $request->validate([
            'reason' => ['nullable', Rule::in(array_keys(LaborSettlement::REASONS))],
            'reason_detail' => ['nullable', 'string', 'max:5000'],
            'lines' => ['array'],
            'lines.*.kind' => ['required', Rule::in(array_keys(LaborSettlementLine::KINDS))],
            'lines.*.code' => ['nullable', 'string', 'max:30'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.detail' => ['nullable', 'string', 'max:255'],
            'lines.*.days' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.daily_rate' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.amount' => ['nullable', 'numeric', 'gte:0'],
        ]);

        if (isset($validated['reason'])) {
            $model->update([
                'reason' => $validated['reason'],
                'reason_detail' => $validated['reason_detail'] ?? $model->reason_detail,
            ]);
        }

        try {
            $this->calculator->calculate(
                $this->company($currentCompany->id()),
                $model->refresh(),
                $validated['lines'] ?? [],
            );
        } catch (InvalidPayrollException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('success', 'Liquidación recalculada.');
    }

    public function approve(Request $request, int $settlement): RedirectResponse
    {
        $model = LaborSettlement::findOrFail($settlement);

        if ($model->status !== 'draft') {
            return back()->withErrors(['payroll' => 'Solo se aprueba una liquidación en borrador.']);
        }

        $model->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Liquidación aprobada. Ya no se recalcula: para cambiarla hay que devolverla a borrador.');
    }

    /**
     * Devuelve una liquidación aprobada a borrador.
     *
     * No hay asiento todavía, así que no hace falta reversión: alcanza con
     * quitar la aprobación y dejar constancia de quién la quitó.
     */
    public function reopen(Request $request, int $settlement): RedirectResponse
    {
        $model = LaborSettlement::findOrFail($settlement);

        if ($model->status !== 'approved') {
            return back()->withErrors(['payroll' => 'Solo se devuelve a borrador una liquidación aprobada.']);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        $model->update([
            'status' => 'draft',
            'approved_at' => null,
            'approved_by' => null,
            'notes' => trim(($model->notes ? $model->notes."\n" : '').
                'Devuelta a borrador el '.now()->format('d/m/Y').': '.$validated['reason']),
        ]);

        return back()->with('success', 'Liquidación devuelta a borrador.');
    }

    public function post(Request $request, int $settlement, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = LaborSettlement::findOrFail($settlement);

        $validated = $request->validate(['posting_date' => ['nullable', 'date']]);

        try {
            $this->poster->post(
                $this->company($currentCompany->id()),
                $model,
                isset($validated['posting_date']) ? new \DateTimeImmutable($validated['posting_date']) : null,
                $request->user()->id,
            );
        } catch (InvalidPayrollException|\RuntimeException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('success',
            'Liquidación contabilizada: se canceló la provisión de cada extremo, se descargaron los días de '.
            'vacaciones pagados y el trabajador quedó inactivo con su fecha de salida.');
    }

    public function void(Request $request, int $settlement, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = LaborSettlement::findOrFail($settlement);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'posting_date' => ['nullable', 'date'],
        ]);

        try {
            $this->poster->void(
                $this->company($currentCompany->id()),
                $model,
                $validated['reason'],
                isset($validated['posting_date']) ? new \DateTimeImmutable($validated['posting_date']) : null,
                $request->user()->id,
            );
        } catch (InvalidPayrollException|\RuntimeException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('success',
            'Liquidación anulada con asiento de reversión: se devolvieron los días de vacaciones y el '.
            'trabajador volvió a estar activo.');
    }

    /**
     * Elimina una liquidación en borrador.
     *
     * Solo en borrador. Una contabilizada se anula: nada contabilizado se
     * borra.
     */
    public function destroy(int $settlement): RedirectResponse
    {
        $model = LaborSettlement::findOrFail($settlement);

        if ($model->status !== 'draft') {
            return back()->withErrors(['payroll' => 'Solo se elimina una liquidación en borrador. Una contabilizada se anula con reversión.']);
        }

        $model->delete();

        return redirect()->route('labor-settlements.index')->with('success', 'Liquidación eliminada.');
    }

    /** @return array<string, mixed> */
    private function row(LaborSettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'employee_id' => $settlement->employee_id,
            'employee_code' => $settlement->employee?->code,
            'employee_name' => $settlement->employee?->fullName(),
            'termination_date' => $settlement->termination_date->format('Y-m-d'),
            'reason' => $settlement->reason,
            'reason_label' => LaborSettlement::REASONS[$settlement->reason] ?? $settlement->reason,
            'status' => $settlement->status,
            'status_label' => LaborSettlement::STATUSES[$settlement->status] ?? $settlement->status,
            'total_gross' => $settlement->total_gross,
            'total_ccss' => $settlement->total_ccss,
            'total_income_tax' => $settlement->total_income_tax,
            'total_other_deductions' => $settlement->total_other_deductions,
            'total_net' => $settlement->total_net,
            'journal_entry_id' => $settlement->journal_entry_id,
        ];
    }

    private function company(int $companyId): Company
    {
        return Company::findOrFail($companyId);
    }
}
