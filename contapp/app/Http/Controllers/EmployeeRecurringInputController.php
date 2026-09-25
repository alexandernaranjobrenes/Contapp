<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeRecurringInput;
use App\Domains\Payroll\Models\PayrollConcept;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rubros fijos por empleado: los que se repiten cada período sin digitarse.
 *
 * Ver el encabezado de la migración sobre por qué la recurrencia es una
 * propiedad de la asignación y no del concepto.
 */
class EmployeeRecurringInputController extends Controller
{
    public function index(): Response
    {
        $items = EmployeeRecurringInput::with([
            'employee:id,code,first_name,last_name1,last_name2',
            'concept:id,code,name,type,calculation,factor',
        ])
            ->orderBy('status')
            ->orderBy('employee_id')
            ->orderBy('id')
            ->get();

        return Inertia::render('Payroll/RecurringInputs/Index', [
            'items' => $items->map(fn (EmployeeRecurringInput $i) => [
                'id' => $i->id,
                'employee_id' => $i->employee_id,
                'employee_code' => $i->employee?->code,
                'employee_name' => $i->employee?->fullName(),
                'payroll_concept_id' => $i->payroll_concept_id,
                'concept_code' => $i->concept?->code,
                'concept_name' => $i->concept?->name,
                'concept_type' => $i->concept?->type,
                'concept_calculation' => $i->concept?->calculation,
                'amount' => $i->amount,
                'quantity' => $i->quantity === null ? null : (float) $i->quantity,
                'start_date' => $i->start_date->format('Y-m-d'),
                'end_date' => $i->end_date?->format('Y-m-d'),
                'notes' => $i->notes,
                'status' => $i->status,
            ])->values(),
            'employees' => Employee::whereIn('status', ['active', 'suspended'])
                ->orderBy('code')
                ->get(['id', 'code', 'first_name', 'last_name1', 'last_name2'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->fullName()]),
            // Solo los conceptos marcados como asignables en fijo. Es lo que
            // le da un uso real a is_recurring, que hasta ahora se guardaba y
            // no lo leía nadie.
            'concepts' => PayrollConcept::where('status', 'active')
                ->where('is_recurring', true)
                ->orderBy('type')->orderBy('code')
                ->get(['id', 'code', 'name', 'type', 'calculation', 'factor'])
                ->map(fn (PayrollConcept $c) => [
                    ...$c->only(['id', 'code', 'name', 'type', 'calculation']),
                    'factor' => $c->factor === null ? null : (float) $c->factor,
                ]),
            // Cuántos conceptos hay en total, para poder decirle al usuario
            // que el catálogo está lleno pero ninguno está marcado como fijo
            // —si no, la lista vacía parece un error del sistema.
            'assignableCount' => PayrollConcept::where('status', 'active')->where('is_recurring', true)->count(),
            'conceptCount' => PayrollConcept::where('status', 'active')->count(),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->rules($companyId));

        $concept = PayrollConcept::findOrFail($validated['payroll_concept_id']);

        if ($error = $this->amountError($concept, $validated)) {
            return back()->withErrors($error)->withInput();
        }

        // El mismo rubro fijo dos veces vigentes a la vez para la misma
        // persona sería un pago doble que nadie pidió. El índice único cubre
        // la misma fecha de inicio; esto cubre el traslape.
        if ($this->overlaps($validated)) {
            return back()->withErrors([
                'start_date' => "Este trabajador ya tiene el rubro {$concept->code} vigente en esas fechas. ".
                    'Cerrá el anterior con una fecha final antes de abrir el nuevo.',
            ])->withInput();
        }

        EmployeeRecurringInput::create([
            ...$validated,
            'company_id' => $companyId,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Rubro fijo asignado. Se va a aplicar en cada planilla vigente.');
    }

    public function update(Request $request, int $recurringInput, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = EmployeeRecurringInput::findOrFail($recurringInput);

        $validated = $request->validate($this->rules($currentCompany->id()));

        $concept = PayrollConcept::findOrFail($validated['payroll_concept_id']);

        if ($error = $this->amountError($concept, $validated)) {
            return back()->withErrors($error)->withInput();
        }

        if ($this->overlaps($validated, $model->id)) {
            return back()->withErrors([
                'start_date' => "Este trabajador ya tiene el rubro {$concept->code} vigente en esas fechas.",
            ])->withInput();
        }

        $model->update($validated);

        return back()->with('success', 'Rubro fijo actualizado.');
    }

    public function destroy(int $recurringInput): RedirectResponse
    {
        $model = EmployeeRecurringInput::findOrFail($recurringInput);

        // Borrar un rubro fijo no cambia las planillas ya calculadas: sus
        // líneas quedaron congeladas en la boleta. Lo que cambia es de acá en
        // adelante, y por eso sí se puede borrar.
        $model->delete();

        return back()->with('success', 'Rubro fijo eliminado. Las planillas ya calculadas no cambian.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(int $companyId): array
    {
        return [
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'payroll_concept_id' => [
                'required',
                // Solo conceptos asignables en fijo: si no, cualquiera podría
                // dejar fijo un rubro que por naturaleza es ocasional.
                Rule::exists('payroll_concepts', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_recurring', true)
                    ->where('status', 'active'),
            ],
            'amount' => ['nullable', 'numeric'],
            'quantity' => ['nullable', 'numeric'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ];
    }

    /**
     * Un concepto por horas sin horas, o uno por monto sin monto, se guardaría
     * en cero y desaparecería del cálculo sin decir nada.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>|null
     */
    private function amountError(PayrollConcept $concept, array $validated): ?array
    {
        if ($concept->calculation === 'hours' && ($validated['quantity'] ?? null) === null) {
            return ['quantity' => "El concepto {$concept->code} se paga por horas: indicá la cantidad."];
        }

        if ($concept->calculation === 'amount' && ($validated['amount'] ?? null) === null) {
            return ['amount' => "El concepto {$concept->code} se digita por monto: indicá el importe."];
        }

        return null;
    }

    /** @param  array<string, mixed>  $validated */
    private function overlaps(array $validated, ?int $ignoreId = null): bool
    {
        return EmployeeRecurringInput::where('employee_id', $validated['employee_id'])
            ->where('payroll_concept_id', $validated['payroll_concept_id'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            // Se traslapan si cada uno empieza antes de que el otro termine.
            // Un end_date nulo es «hasta siempre», así que cubre cualquier
            // fecha posterior.
            ->where('start_date', '<=', $validated['end_date'] ?? '9999-12-31')
            ->where(function ($q) use ($validated) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $validated['start_date']);
            })
            ->exists();
    }
}
