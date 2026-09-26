<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La bitácora del funcionario: hechos, anotaciones y observaciones.
 *
 * No tiene pantalla propia — vive dentro de la ficha del empleado, que es
 * donde se consulta. Ver el encabezado de la migración sobre por qué está
 * separada de las acciones de personal.
 */
class EmployeeNoteController extends Controller
{
    public function store(Request $request, int $employee, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = Employee::findOrFail($employee);

        $validated = $request->validate([
            // La fecha del HECHO, no la de la anotación: se registra hoy algo
            // que pasó la semana pasada, y ordenar por la fecha de captura
            // contaría la historia en el orden equivocado.
            'happened_on' => ['required', 'date'],
            'category' => ['required', Rule::in(array_keys(EmployeeNote::CATEGORIES))],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_confidential' => ['boolean'],
        ]);

        if ($validated['happened_on'] < $model->hire_date->format('Y-m-d')) {
            return back()->withErrors([
                'happened_on' => "La fecha no puede ser anterior al ingreso del trabajador ({$model->hire_date->format('Y-m-d')}).",
            ])->withInput();
        }

        EmployeeNote::create([
            ...$validated,
            'company_id' => $currentCompany->id(),
            'employee_id' => $model->id,
            'is_confidential' => $validated['is_confidential'] ?? false,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Anotación registrada en la bitácora.');
    }

    /**
     * Solo la puede borrar quien la escribió, y solo el mismo día.
     *
     * Una bitácora que se puede editar después no sirve para sustentar nada:
     * el valor de la anotación está en que se hizo cuando pasó el hecho. Lo
     * que sí se puede es corregir anotando encima, que además deja ver que
     * hubo una corrección.
     *
     * La ventana del mismo día existe para el error de dedo evidente, no
     * para repensar lo que se escribió.
     */
    public function destroy(Request $request, int $employee, int $note): RedirectResponse
    {
        $model = EmployeeNote::where('employee_id', $employee)->findOrFail($note);

        if ($model->created_by !== $request->user()->id) {
            return back()->withErrors([
                'note' => 'Solo quien escribió la anotación puede borrarla. Si hay que corregirla, anotá encima: '.
                    'así queda constancia de que hubo una corrección.',
            ]);
        }

        if (! $model->created_at->isToday()) {
            return back()->withErrors([
                'note' => 'Una anotación solo se puede borrar el mismo día en que se escribió. '.
                    'Después, lo que corresponde es anotar encima.',
            ]);
        }

        $model->delete();

        return back()->with('success', 'Anotación eliminada.');
    }
}
