<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\PayrollConcept;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las obligaciones recurrentes del trabajador: adelantos, préstamos, ahorro
 * y cuota solidarista, pensión alimentaria, embargos.
 *
 * Una sola pantalla para todas porque son una sola cosa: un monto que se
 * rebaja cada período contra una cuenta. Ver el encabezado de la migración.
 */
class EmployeeDeductionController extends Controller
{
    public function index(): Response
    {
        $deductions = EmployeeDeduction::with([
            'employee:id,code,first_name,last_name1,last_name2',
            'concept:id,code,name',
        ])
            ->withCount('applications')
            ->orderBy('status')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return Inertia::render('Payroll/Deductions/Index', [
            'deductions' => $deductions->map(fn (EmployeeDeduction $d) => [
                'id' => $d->id,
                'employee_id' => $d->employee_id,
                'employee_code' => $d->employee?->code,
                'employee_name' => $d->employee?->fullName(),
                'type' => $d->type,
                'type_label' => EmployeeDeduction::TYPES[$d->type] ?? $d->type,
                'reference' => $d->reference,
                'description' => $d->description,
                'payroll_concept_id' => $d->payroll_concept_id,
                'concept_code' => $d->concept?->code,
                'start_date' => $d->start_date->format('Y-m-d'),
                'end_date' => $d->end_date?->format('Y-m-d'),
                'original_amount' => $d->original_amount,
                'balance' => $d->balance,
                'calculation' => $d->calculation,
                'installment_amount' => $d->installment_amount,
                'installment_percentage' => $d->installment_percentage === null
                    ? null : (float) $d->installment_percentage,
                'priority' => $d->priority,
                'account_id' => $d->account_id,
                'status' => $d->status,
                'applications_count' => $d->applications_count,
            ])->values(),
            'types' => EmployeeDeduction::TYPES,
            'defaultPriorities' => EmployeeDeduction::DEFAULT_PRIORITY,
            'employees' => Employee::whereIn('status', ['active', 'suspended'])
                ->orderBy('code')
                ->get(['id', 'code', 'first_name', 'last_name1', 'last_name2'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->fullName()]),
            'concepts' => PayrollConcept::where('type', 'deduction')
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->rules($companyId));

        EmployeeDeduction::create([
            ...$this->normalize($validated),
            'company_id' => $companyId,
        ]);

        return back()->with('success', 'Obligación registrada.');
    }

    public function update(Request $request, int $deduction, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = EmployeeDeduction::findOrFail($deduction);

        $validated = $request->validate($this->rules($currentCompany->id()));

        // El saldo de una obligación que ya tuvo rebajos no se edita a mano:
        // se movería sin que ninguna de sus aplicaciones lo explique, y el
        // estado de cuenta que se le enseña al trabajador dejaría de cuadrar.
        if ($model->applications()->exists()
            && $validated['balance'] !== null
            && bccomp((string) $validated['balance'], (string) $model->balance, 2) !== 0) {
            return back()->withErrors([
                'balance' => 'Esta obligación ya tiene rebajos aplicados: el saldo no se corrige a mano. '.
                    'Anulala y registrá una nueva por el saldo correcto.',
            ])->withInput();
        }

        $model->update($this->normalize($validated));

        return back()->with('success', 'Obligación actualizada.');
    }

    public function destroy(int $deduction): RedirectResponse
    {
        $model = EmployeeDeduction::findOrFail($deduction);

        if ($model->applications()->exists()) {
            return back()->withErrors([
                'deduction' => 'Esta obligación ya se rebajó en una planilla. Suspendela o anulala en vez de borrarla: '.
                    'borrarla dejaría los rebajos ya hechos sin nada que los explique.',
            ]);
        }

        $model->delete();

        return back()->with('success', 'Obligación eliminada.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(int $companyId): array
    {
        return [
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'type' => ['required', Rule::in(array_keys(EmployeeDeduction::TYPES))],
            'reference' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:255'],
            'payroll_concept_id' => [
                'nullable',
                Rule::exists('payroll_concepts', 'id')->where('company_id', $companyId),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'original_amount' => ['nullable', 'numeric', 'gt:0'],
            // Vacío significa "sin saldo que extinguir": un ahorro o una
            // cuota que se rebaja indefinidamente. No es lo mismo que cero,
            // que sería una obligación ya pagada.
            'balance' => ['nullable', 'numeric', 'gte:0'],
            'calculation' => ['required', Rule::in(['amount', 'percentage'])],
            'installment_amount' => ['nullable', 'required_if:calculation,amount', 'numeric', 'gt:0'],
            'installment_percentage' => ['nullable', 'required_if:calculation,percentage', 'numeric', 'gt:0', 'max:100'],
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
            'account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'status' => ['required', Rule::in(['active', 'suspended', 'settled', 'cancelled'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Un préstamo sin saldo inicial arranca con el monto otorgado: nadie
     * digita el mismo número dos veces, y olvidarlo dejaría la obligación
     * rebajando para siempre.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalize(array $validated): array
    {
        $settles = in_array($validated['type'], EmployeeDeduction::SETTLING_TYPES, true);

        return [
            ...$validated,
            'balance' => $validated['balance']
                ?? ($settles ? ($validated['original_amount'] ?? null) : null),
        ];
    }
}
