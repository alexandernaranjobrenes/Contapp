<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PersonnelAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Acciones de personal: aumentos, traslados, cambios de jornada, suspensiones
 * y terminaciones.
 *
 * ── Por qué no se edita la ficha directamente ────────────────────────────
 *
 * Un aumento de salario no es una edición. Es un hecho con fecha de
 * vigencia, un antes y un después, un motivo y un responsable. Editar
 * `base_salary` a mano deja la planilla del mes pasado sin explicación y a
 * nadie en condición de decir desde cuándo rige ni quién lo aprobó.
 *
 * ── El flujo tiene tres pasos y el orden importa ─────────────────────────
 *
 *   borrador → aprobada → aplicada
 *
 * Aplicar es lo único que toca la ficha. Separarlo de aprobar permite que
 * un aumento acordado hoy con vigencia del mes entrante quede aprobado
 * ahora y entre a la ficha cuando corresponde, en vez de tener que
 * acordarse de digitarlo ese día.
 */
class PersonnelActionController extends Controller
{
    public function index(): Response
    {
        $actions = PersonnelAction::with([
            'employee:id,code,first_name,last_name1,last_name2',
            'requestedBy:id,name',
            'approvedBy:id,name',
        ])
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        return Inertia::render('Payroll/PersonnelActions/Index', [
            'actions' => $actions->map(fn (PersonnelAction $a) => [
                'id' => $a->id,
                'employee_id' => $a->employee_id,
                'employee_code' => $a->employee?->code,
                'employee_name' => $a->employee?->fullName(),
                'action_type' => $a->action_type,
                'action_label' => PersonnelAction::TYPES[$a->action_type] ?? $a->action_type,
                'effective_date' => $a->effective_date->format('Y-m-d'),
                'field' => $a->field,
                'previous_value' => $a->previous_value,
                'new_value' => $a->new_value,
                'reason' => $a->reason,
                'status' => $a->status,
                'status_label' => PersonnelAction::STATUSES[$a->status] ?? $a->status,
                'requested_by' => $a->requestedBy?->name,
                'approved_by' => $a->approvedBy?->name,
                'approved_at' => $a->approved_at?->format('Y-m-d H:i'),
                'applied_at' => $a->applied_at?->format('Y-m-d H:i'),
                // Una acción con vigencia futura no se aplica todavía: se
                // aprueba y espera. La pantalla lo necesita para no ofrecer
                // un botón que va a rebotar.
                'is_due' => $a->effective_date->format('Y-m-d') <= now()->format('Y-m-d'),
            ])->values(),
            'employees' => Employee::orderBy('code')
                ->get(['id', 'code', 'first_name', 'last_name1', 'last_name2', 'base_salary', 'position', 'cost_center_id', 'journey_type', 'status'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id, 'code' => $e->code, 'name' => $e->fullName(),
                    'base_salary' => $e->base_salary, 'position' => $e->position,
                    'cost_center_id' => $e->cost_center_id, 'journey_type' => $e->journey_type,
                    'status' => $e->status,
                ]),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'types' => PersonnelAction::TYPES,
            'statuses' => PersonnelAction::STATUSES,
            'fieldByType' => PersonnelAction::FIELD_BY_TYPE,
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'action_type' => ['required', Rule::in(array_keys(PersonnelAction::TYPES))],
            'effective_date' => ['required', 'date'],
            'new_value' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $field = PersonnelAction::FIELD_BY_TYPE[$validated['action_type']] ?? null;

        // La vigencia no puede ser anterior al ingreso: un aumento que rige
        // antes de que la persona entrara a trabajar no significa nada, y
        // desordenaría el historial al ordenarlo por fecha.
        if ($validated['effective_date'] < $employee->hire_date->format('Y-m-d')) {
            return back()->withErrors([
                'effective_date' => "La vigencia no puede ser anterior al ingreso del trabajador ({$employee->hire_date->format('Y-m-d')}).",
            ])->withInput();
        }

        // Los tipos que cambian un campo exigen el valor nuevo: sin él la
        // acción quedaría sin nada que aplicar.
        if ($field !== null && ($validated['new_value'] ?? null) === null) {
            return back()->withErrors([
                'new_value' => 'Indicá el valor nuevo: es lo que la acción va a dejar en la ficha.',
            ])->withInput();
        }

        PersonnelAction::create([
            ...$validated,
            'company_id' => $companyId,
            'field' => $field,
            // El valor anterior se congela al CREAR la acción, no al
            // aplicarla: es el que había cuando se tomó la decisión, y es lo
            // que hace legible el historial.
            'previous_value' => $field === null ? null : (string) $employee->{$field},
            'status' => 'draft',
            'requested_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Acción de personal registrada en borrador.');
    }

    public function approve(Request $request, int $personnelAction): RedirectResponse
    {
        $action = PersonnelAction::findOrFail($personnelAction);

        if ($action->status !== 'draft') {
            return back()->withErrors([
                'action' => 'Solo se aprueba una acción en borrador. Esta está '.
                    mb_strtolower(PersonnelAction::STATUSES[$action->status] ?? $action->status).'.',
            ]);
        }

        // Quien la pidió no la aprueba: una aprobación que uno se da a sí
        // mismo no es una aprobación, y el campo separado de la migración
        // no serviría de nada si acá no se respetara.
        if ($action->requested_by === $request->user()->id && ! $request->user()->isSuperAdmin($action->company_id)) {
            return back()->withErrors([
                'action' => 'No podés aprobar una acción que vos mismo solicitaste. '.
                    'La tiene que aprobar otra persona con permiso sobre planillas.',
            ]);
        }

        $action->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Acción aprobada.');
    }

    /**
     * Aplica la acción a la ficha del trabajador. Es el único paso que la
     * modifica.
     */
    public function apply(int $personnelAction): RedirectResponse
    {
        $action = PersonnelAction::findOrFail($personnelAction);

        if ($action->status !== 'approved') {
            return back()->withErrors([
                'action' => 'Solo se aplica una acción aprobada.',
            ]);
        }

        if ($action->effective_date->format('Y-m-d') > now()->format('Y-m-d')) {
            return back()->withErrors([
                'action' => "Esta acción rige desde el {$action->effective_date->format('Y-m-d')}: todavía no se puede aplicar.",
            ]);
        }

        $employee = Employee::findOrFail($action->employee_id);

        $changes = match ($action->action_type) {
            'termination' => [
                'status' => 'terminated',
                'termination_date' => $action->effective_date->format('Y-m-d'),
                'termination_reason' => $action->new_value,
            ],
            'suspension' => ['status' => 'suspended'],
            'reinstatement' => ['status' => 'active'],
            default => $action->field === null ? [] : [$action->field => $action->new_value],
        };

        if ($changes !== []) {
            $employee->update($changes);
        }

        $action->update(['status' => 'applied', 'applied_at' => now()]);

        return back()->with('success', "Acción aplicada a la ficha de {$employee->fullName()}.");
    }

    public function cancel(int $personnelAction): RedirectResponse
    {
        $action = PersonnelAction::findOrFail($personnelAction);

        // Una acción aplicada ya cambió la ficha: anularla no desharía el
        // cambio, solo escondería por qué se hizo. Lo que corresponde es
        // registrar otra acción que lo revierta.
        if ($action->status === 'applied') {
            return back()->withErrors([
                'action' => 'Esta acción ya se aplicó a la ficha. Para revertirla, registrá una acción nueva '.
                    'que devuelva el valor anterior: así queda el rastro de las dos decisiones.',
            ]);
        }

        $action->update(['status' => 'cancelled']);

        return back()->with('success', 'Acción anulada.');
    }
}
