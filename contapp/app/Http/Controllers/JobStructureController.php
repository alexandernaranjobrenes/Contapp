<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Support\CodeRules;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\JobPosition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Departamentos y puestos: la estructura organizacional.
 *
 * Los dos en una pantalla porque se administran juntos —un puesto pertenece
 * a un departamento— y separarlos obligaría a ir y venir para armar la
 * estructura de una empresa nueva.
 */
class JobStructureController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Payroll/JobStructure/Index', [
            // El código que sigue (CodeRules): se propone al crear.
            'nextDepartmentCode' => CodeRules::following(Department::query()),
            'nextPositionCode' => CodeRules::following(JobPosition::query()),
            'departments' => Department::with('costCenter:id,code,name')
                ->withCount(['employees', 'positions'])
                ->orderBy('code')
                ->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'code' => $d->code,
                    'name' => $d->name,
                    'cost_center_id' => $d->cost_center_id,
                    'cost_center' => $d->costCenter?->code,
                    'status' => $d->status,
                    'employees_count' => $d->employees_count,
                    // Con gente o puestos adentro, el código queda fijo (CodeRules).
                    'code_locked' => $d->employees_count > 0 || $d->positions_count > 0,
                ])->values(),
            'positions' => JobPosition::with('department:id,code,name')
                ->withCount('employees')
                ->orderBy('code')
                ->get()
                ->map(fn (JobPosition $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'department_id' => $p->department_id,
                    'department' => $p->department?->name,
                    'ccss_occupation_code' => $p->ccss_occupation_code,
                    'ccss_occupation_name' => $p->ccss_occupation_name,
                    'min_salary' => $p->min_salary,
                    'max_salary' => $p->max_salary,
                    'description' => $p->description,
                    'status' => $p->status,
                    'employees_count' => $p->employees_count,
                    'code_locked' => $p->employees_count > 0,
                ])->values(),
            'costCenters' => CostCenter::where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    // ── Departamentos ───────────────────────────────────────────────────

    public function storeDepartment(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->departmentRules($companyId));

        Department::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', "Departamento {$validated['code']} creado.");
    }

    public function updateDepartment(Request $request, int $department, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = Department::findOrFail($department);

        $validated = $request->validate($this->departmentRules($currentCompany->id(), $model->id));
        CodeRules::ensureUnchanged($model->codeLockReason(), $model->code, $validated['code']);

        $model->update($validated);

        return back()->with('success', "Departamento {$model->code} actualizado.");
    }

    public function destroyDepartment(int $department): RedirectResponse
    {
        $model = Department::findOrFail($department);

        // Con gente adentro no se borra: las fichas quedarían apuntando al
        // vacío y los reportes por departamento perderían ese grupo entero.
        if ($model->employees()->exists()) {
            return back()->withErrors([
                'structure' => "El departamento {$model->code} tiene trabajadores asignados. ".
                    'Movelos a otro departamento o desactivalo en vez de borrarlo.',
            ]);
        }

        if ($model->positions()->exists()) {
            return back()->withErrors([
                'structure' => "El departamento {$model->code} tiene puestos asignados.",
            ]);
        }

        $model->delete();

        return back()->with('success', 'Departamento eliminado.');
    }

    // ── Puestos ─────────────────────────────────────────────────────────

    public function storePosition(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->positionRules($companyId));

        JobPosition::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', "Puesto {$validated['code']} creado.");
    }

    public function updatePosition(Request $request, int $position, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = JobPosition::findOrFail($position);

        $validated = $request->validate($this->positionRules($currentCompany->id(), $model->id));
        CodeRules::ensureUnchanged($model->codeLockReason(), $model->code, $validated['code']);

        $model->update($validated);

        return back()->with('success', "Puesto {$model->code} actualizado.");
    }

    public function destroyPosition(int $position): RedirectResponse
    {
        $model = JobPosition::findOrFail($position);

        if ($model->employees()->exists()) {
            return back()->withErrors([
                'structure' => "El puesto {$model->code} tiene trabajadores asignados. ".
                    'Movelos a otro puesto o desactivalo en vez de borrarlo.',
            ]);
        }

        $model->delete();

        return back()->with('success', 'Puesto eliminado.');
    }

    /** @return array<string, array<int, mixed>> */
    private function departmentRules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('departments', 'code')->where('company_id', $companyId)->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')->where('company_id', $companyId)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function positionRules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('job_positions', 'code')->where('company_id', $companyId)->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'ccss_occupation_code' => ['nullable', 'string', 'max:20'],
            'ccss_occupation_name' => ['nullable', 'string', 'max:255'],
            'min_salary' => ['nullable', 'numeric', 'gte:0'],
            // El máximo por debajo del mínimo dejaría un rango que ningún
            // salario puede cumplir, y la advertencia saltaría siempre.
            'max_salary' => ['nullable', 'numeric', 'gte:0', 'gte:min_salary'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
