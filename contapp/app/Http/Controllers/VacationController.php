<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\VacationMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vacaciones: saldo por trabajador y sus movimientos.
 *
 * El saldo es la SUMA de los movimientos y no un campo guardado — ver el
 * encabezado de la migración. Acá se muestran los dos juntos porque la
 * pregunta real nunca es "cuántos días tiene" sino "cuántos días tiene y
 * de dónde salieron".
 */
class VacationController extends Controller
{
    public function index(): Response
    {
        // Los saldos se calculan con UNA agregación y no empleado por
        // empleado: una planilla de doscientas personas haría doscientas
        // consultas y la pantalla tardaría lo que tarda el hábito.
        $balances = VacationMovement::query()
            ->selectRaw('employee_id, SUM(days) as balance')
            ->groupBy('employee_id')
            ->pluck('balance', 'employee_id');

        $employees = Employee::with('costCenter:id,code')
            ->whereIn('status', ['active', 'suspended'])
            ->orderBy('code')
            ->get();

        return Inertia::render('Payroll/Vacations/Index', [
            'employees' => $employees->map(fn (Employee $e) => [
                'id' => $e->id,
                'code' => $e->code,
                'name' => $e->fullName(),
                'position' => $e->position,
                'cost_center' => $e->costCenter?->code,
                'hire_date' => $e->hire_date->format('Y-m-d'),
                'years_of_service' => $e->yearsOfService(now()),
                'balance' => (float) ($balances[$e->id] ?? 0),
                // El valor del día es lo que vale cada día acumulado si
                // hubiera que pagarlo: sin esto el saldo es un número sin
                // consecuencia económica visible.
                'daily_rate' => $e->dailyRate(),
            ])->values(),
            'movements' => VacationMovement::with(['employee:id,code,first_name,last_name1,last_name2'])
                ->orderByDesc('movement_date')->orderByDesc('id')
                ->limit(300)
                ->get()
                ->map(fn (VacationMovement $m) => [
                    'id' => $m->id,
                    'employee_id' => $m->employee_id,
                    'employee_code' => $m->employee?->code,
                    'employee_name' => $m->employee?->fullName(),
                    'type' => $m->type,
                    'type_label' => VacationMovement::TYPES[$m->type] ?? $m->type,
                    'movement_date' => $m->movement_date->format('Y-m-d'),
                    'days' => (float) $m->days,
                    'from_date' => $m->from_date?->format('Y-m-d'),
                    'to_date' => $m->to_date?->format('Y-m-d'),
                    'amount' => $m->amount,
                    'notes' => $m->notes,
                    // Las acreditaciones automáticas no se borran a mano:
                    // las rehace el recálculo de su período.
                    'is_automatic' => $m->type === 'accrual' && $m->payroll_period_id !== null,
                ])->values(),
            'types' => VacationMovement::TYPES,
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            // La acreditación automática la hace el motor al calcular la
            // planilla; a mano solo se registran disfrutes, pagos y ajustes.
            'type' => ['required', Rule::in(['taken', 'paid', 'adjustment'])],
            'movement_date' => ['required', 'date'],
            'days' => ['required', 'numeric', 'not_in:0'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'amount' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        // Un disfrute o un pago REBAJAN: se guardan en negativo para que el
        // saldo siga siendo una suma simple. Digitarlos en positivo es el
        // error natural, y aceptarlo tal cual sumaría días en vez de
        // restarlos — el saldo crecería cada vez que alguien saliera de
        // vacaciones.
        $days = (string) $validated['days'];

        if (in_array($validated['type'], ['taken', 'paid'], true)) {
            $days = bccomp($days, '0', 4) > 0 ? bcmul($days, '-1', 4) : $days;
        }

        $balance = $employee->vacationBalance();
        $resulting = bcadd($balance, $days, 4);

        // Un saldo negativo significa días disfrutados que no se han ganado.
        // Puede ser legítimo —vacaciones adelantadas— pero tiene que ser una
        // decisión, no un descuido: se avisa y se deja pasar solo con el
        // ajuste explícito.
        if (bccomp($resulting, '0', 4) < 0 && $validated['type'] !== 'adjustment') {
            return back()->withErrors([
                'days' => "El trabajador tiene {$balance} día(s) acumulado(s) y esto lo dejaría en {$resulting}. ".
                    'Si querés adelantarle vacaciones, registralo como ajuste con el motivo.',
            ])->withInput();
        }

        VacationMovement::create([
            ...$validated,
            'company_id' => $companyId,
            'days' => $days,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Movimiento de vacaciones registrado.');
    }

    public function destroy(int $movement): RedirectResponse
    {
        $model = VacationMovement::findOrFail($movement);

        // Las acreditaciones automáticas pertenecen a su período: borrarlas
        // a mano las haría reaparecer al siguiente recálculo y dejaría al
        // usuario peleando con un número que no controla.
        if ($model->type === 'accrual' && $model->payroll_period_id !== null) {
            return back()->withErrors([
                'movement' => 'Esta acreditación la generó el cálculo de su período. '.
                    'Se rehace sola al recalcular esa planilla; si el número está mal, corregí los días por mes '.
                    'en la configuración y recalculá.',
            ]);
        }

        $model->delete();

        return back()->with('success', 'Movimiento eliminado.');
    }
}
