<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\EmployeeNote;
use App\Domains\Payroll\Models\JobPosition;
use App\Domains\Payroll\Models\PersonnelAction;
use App\Domains\Payroll\Models\VacationMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(private readonly MediaStorage $media) {}

    public function index(): Response
    {
        $employees = Employee::with(['costCenter:id,code,name'])
            ->orderBy('code')
            ->get();

        return Inertia::render('Payroll/Employees/Index', [
            'employees' => $employees->map(fn (Employee $e) => $this->row($e))->values(),
            'costCenters' => CostCenter::where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            // El catálogo va con el centro de costo de cada departamento: la
            // ficha lo propone al elegirlo, para no tener que acordarse.
            'departmentOptions' => Department::where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name', 'cost_center_id']),
            'positionOptions' => JobPosition::where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name', 'department_id', 'min_salary', 'max_salary']),
            'expenseAccounts' => ChartOfAccount::where('accepts_posting', true)
                ->whereIn('account_type', ['expense', 'cost_of_sales'])
                ->orderBy('code')->get(['id', 'code', 'description_es']),
            'options' => $this->options(),
        ]);
    }

    /**
     * La ficha completa: identidad, relación laboral y su historia.
     *
     * Se muestran juntos el saldo de vacaciones, las obligaciones vivas y
     * las acciones de personal porque son las tres preguntas que se hacen
     * sobre un trabajador y ninguna se responde con la ficha sola.
     */
    public function show(int $employee): Response
    {
        $model = Employee::with(['costCenter:id,code,name'])->findOrFail($employee);

        $vacations = VacationMovement::where('employee_id', $model->id)
            ->orderByDesc('movement_date')->orderByDesc('id')
            ->get();

        return Inertia::render('Payroll/Employees/Show', [
            'employee' => $this->row($model) + [
                'birth_date' => $model->birth_date?->format('Y-m-d'),
                'gender' => $model->gender,
                'nationality' => $model->nationality,
                'address' => $model->address,
                'ccss_number' => $model->ccss_number,
                'contract_type' => $model->contract_type,
                'weekly_hours' => (float) $model->weekly_hours,
                'bank_name' => $model->bank_name,
                'notes' => $model->notes,
                'termination_reason' => $model->termination_reason,
                'salary_expense_account_id' => $model->salary_expense_account_id,
                'hourly_rate' => $model->hourlyRate(),
                'daily_rate' => $model->dailyRate(),
                'monthly_salary' => $model->monthlySalary(),
                'years_of_service' => $model->yearsOfService(now()),
            ],
            // El saldo es la SUMA de los movimientos, no un campo: ver el
            // encabezado de la migración de vacaciones.
            'vacationBalance' => $vacations->reduce(
                fn ($carry, VacationMovement $m) => bcadd($carry, (string) $m->days, 4), '0.0000'
            ),
            'vacationMovements' => $vacations->map(fn (VacationMovement $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'type_label' => VacationMovement::TYPES[$m->type] ?? $m->type,
                'movement_date' => $m->movement_date->format('Y-m-d'),
                'days' => (float) $m->days,
                'from_date' => $m->from_date?->format('Y-m-d'),
                'to_date' => $m->to_date?->format('Y-m-d'),
                'amount' => $m->amount,
                'notes' => $m->notes,
            ])->values(),
            'deductions' => EmployeeDeduction::with('concept:id,code,name')
                ->where('employee_id', $model->id)
                ->orderBy('priority')->orderBy('id')
                ->get()
                ->map(fn (EmployeeDeduction $d) => [
                    'id' => $d->id,
                    'type' => $d->type,
                    'type_label' => EmployeeDeduction::TYPES[$d->type] ?? $d->type,
                    'description' => $d->description,
                    'reference' => $d->reference,
                    'start_date' => $d->start_date->format('Y-m-d'),
                    'end_date' => $d->end_date?->format('Y-m-d'),
                    'original_amount' => $d->original_amount,
                    'balance' => $d->balance,
                    'calculation' => $d->calculation,
                    'installment_amount' => $d->installment_amount,
                    'installment_percentage' => $d->installment_percentage,
                    'priority' => $d->priority,
                    'status' => $d->status,
                ])->values(),
            'personnelActions' => PersonnelAction::where('employee_id', $model->id)
                ->orderByDesc('effective_date')->orderByDesc('id')
                ->get()
                ->map(fn (PersonnelAction $a) => [
                    'id' => $a->id,
                    'action_type' => $a->action_type,
                    'action_label' => PersonnelAction::TYPES[$a->action_type] ?? $a->action_type,
                    'effective_date' => $a->effective_date->format('Y-m-d'),
                    'field' => $a->field,
                    'previous_value' => $a->previous_value,
                    'new_value' => $a->new_value,
                    'reason' => $a->reason,
                    'status' => $a->status,
                    'status_label' => PersonnelAction::STATUSES[$a->status] ?? $a->status,
                ])->values(),
            // La bitácora: hechos con fecha, en el orden en que pasaron y no
            // en el que se digitaron.
            'notes' => EmployeeNote::with('createdBy:id,name')
                ->where('employee_id', $model->id)
                ->orderByDesc('happened_on')->orderByDesc('id')
                ->get()
                ->map(fn (EmployeeNote $n) => [
                    'id' => $n->id,
                    'happened_on' => $n->happened_on->format('Y-m-d'),
                    'category' => $n->category,
                    'category_label' => EmployeeNote::CATEGORIES[$n->category] ?? $n->category,
                    'title' => $n->title,
                    'body' => $n->body,
                    'is_confidential' => (bool) $n->is_confidential,
                    'user' => $n->createdBy?->name,
                    'at' => $n->created_at?->format('Y-m-d H:i'),
                    // Solo quien la escribió y solo el mismo día: ver el
                    // controlador de la bitácora.
                    'can_delete' => $n->created_by === request()->user()?->id && $n->created_at?->isToday(),
                ])->values(),
            'noteCategories' => EmployeeNote::CATEGORIES,
            'options' => $this->options(),
            'costCenters' => CostCenter::where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $validated = $request->validate($this->rules($currentCompany->id()));

        $employee = Employee::create([
            ...$this->normalize($validated),
            'company_id' => $currentCompany->id(),
        ]);

        // La contratación queda registrada como acción de personal desde el
        // primer día: así el historial del trabajador empieza donde empezó
        // de verdad y no en su primer aumento.
        PersonnelAction::create([
            'company_id' => $currentCompany->id(),
            'employee_id' => $employee->id,
            'action_type' => 'hire',
            'effective_date' => $employee->hire_date->format('Y-m-d'),
            'new_value' => $employee->base_salary,
            'field' => 'base_salary',
            'status' => 'applied',
            'requested_by' => $request->user()->id,
            'applied_at' => now(),
            'reason' => 'Contratación',
        ]);

        return back()->with('success', "Empleado {$employee->code} creado.");
    }

    public function update(Request $request, int $employee, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = Employee::findOrFail($employee);

        $validated = $request->validate($this->rules($currentCompany->id(), $model->id));

        $model->update($this->normalize($validated));

        return back()->with('success', "Empleado {$model->code} actualizado.");
    }

    /**
     * La fotografía. Va al disco de imágenes (MediaStorage) con dirección
     * pública porque se muestra en pantalla y en el comprobante de pago; no
     * lleva dato sensible que justifique servirla a través de una ruta
     * autenticada.
     */
    public function photo(Request $request, int $employee): RedirectResponse
    {
        $model = Employee::findOrFail($employee);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $path = $this->media->store($request->file('photo'), "employees/{$model->company_id}");

        if ($path === null) {
            return back()->withErrors([
                'photo' => 'No se pudo guardar la fotografía. Probá de nuevo en un momento.',
            ]);
        }

        $previous = $model->photo_path;

        $model->update(['photo_path' => $path]);

        // La anterior se borra: dejarla acumularía una foto por cada cambio
        // de cada empleado, y ninguna vuelve a usarse. Recién ahora, con la
        // nueva ya guardada: si la subida falla, la ficha conserva su foto.
        $this->media->delete($previous);

        return back()->with('success', 'Fotografía actualizada.');
    }

    public function destroy(int $employee): RedirectResponse
    {
        $model = Employee::findOrFail($employee);

        // Un empleado con planilla no se borra: su boleta es un hecho
        // histórico y borrarlo dejaría planillas apuntando al vacío. Lo que
        // corresponde es darlo de baja con su fecha y su motivo.
        if ($model->entries()->exists()) {
            return back()->withErrors([
                'employee' => "El empleado {$model->code} ya tiene planillas calculadas y no se puede eliminar. ".
                    'Registrá su terminación con la fecha y el motivo.',
            ]);
        }

        $model->delete();

        return back()->with('success', 'Empleado eliminado.');
    }

    /** @return array<string, mixed> */
    private function row(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'code' => $employee->code,
            'full_name' => $employee->fullName(),
            'first_name' => $employee->first_name,
            'last_name1' => $employee->last_name1,
            'last_name2' => $employee->last_name2,
            'identification_type' => $employee->identification_type,
            'identification_number' => $employee->identification_number,
            'email' => $employee->email,
            'phone' => $employee->phone,
            // Lo que se muestra: el catálogo si lo tiene, el texto escrito a
            // mano si no. Las dos formas conviven mientras las fichas viejas
            // se mapean.
            'position' => $employee->positionLabel(),
            'department' => $employee->departmentLabel(),
            'department_id' => $employee->department_id,
            'job_position_id' => $employee->job_position_id,
            'cost_center_id' => $employee->cost_center_id,
            'cost_center' => $employee->costCenter?->code,
            'hire_date' => $employee->hire_date->format('Y-m-d'),
            'termination_date' => $employee->termination_date?->format('Y-m-d'),
            'journey_type' => $employee->journey_type,
            'salary_type' => $employee->salary_type,
            'base_salary' => $employee->base_salary,
            'weekly_salary_divisor' => (int) ($employee->weekly_salary_divisor ?: Employee::DEFAULT_WEEKLY_DIVISOR),
            'payment_method' => $employee->payment_method,
            'bank_account' => $employee->bank_account,
            'has_spouse_credit' => (bool) $employee->has_spouse_credit,
            'children_credit_count' => $employee->children_credit_count,
            'is_income_tax_exempt' => (bool) $employee->is_income_tax_exempt,
            'is_ccss_exempt' => (bool) $employee->is_ccss_exempt,
            'is_pensioner' => (bool) $employee->is_pensioner,
            'status' => $employee->status,
            'photo_url' => $this->media->url($employee->photo_path),
            // La jornada ordinaria que le corresponde: es el umbral a partir
            // del cual una hora es extra, y no es el mismo para todos.
            'ordinary_hours' => Employee::ORDINARY_HOURS[$employee->journey_type] ?? null,
            // El valor del día y de la hora, derivados. Son el primitivo con
            // el que se pagan vacaciones, aguinaldo, incapacidades, horas
            // extra y liquidaciones. Van en la fila —y no solo en la ficha—
            // porque un divisor mal escogido se detecta de una sola pasada
            // sobre la lista, antes de calcular la planilla.
            'day_rate' => $employee->dailyRate(),
            'hour_rate' => $employee->hourlyRate(),
        ];
    }

    /** @return array<string, array<string, string>> */
    private function options(): array
    {
        return [
            'journeyTypes' => [
                'diurna' => 'Diurna (8 h ordinarias)',
                'mixta' => 'Mixta (7 h ordinarias)',
                'nocturna' => 'Nocturna (6 h ordinarias)',
            ],
            'salaryTypes' => [
                'mensual' => 'Mensual', 'quincenal' => 'Quincenal', 'semanal' => 'Semanal',
                'diario' => 'Diario', 'hora' => 'Por hora',
            ],
            'contractTypes' => [
                'indefinido' => 'Tiempo indefinido', 'plazo_fijo' => 'Plazo fijo',
                'obra_determinada' => 'Obra determinada', 'ocasional' => 'Ocasional',
            ],
            'identificationTypes' => [
                'cedula' => 'Cédula', 'dimex' => 'DIMEX', 'pasaporte' => 'Pasaporte',
            ],
            'paymentMethods' => [
                'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'efectivo' => 'Efectivo',
            ],
            'terminationReasons' => [
                'renuncia' => 'Renuncia', 'despido_con_causa' => 'Despido con responsabilidad del trabajador',
                'despido_sin_causa' => 'Despido sin justa causa', 'vencimiento' => 'Vencimiento del plazo',
                'mutuo_acuerdo' => 'Mutuo acuerdo', 'fallecimiento' => 'Fallecimiento',
            ],
            'statuses' => [
                'active' => 'Activo', 'suspended' => 'Suspendido', 'terminated' => 'Terminado',
            ],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('employees', 'code')->where('company_id', $companyId)->ignore($ignoreId),
            ],
            'identification_type' => ['required', Rule::in(['cedula', 'dimex', 'pasaporte'])],
            // La identificación no se repite: dos fichas de la misma persona
            // duplicarían su planilla y sus cargas ante la Caja.
            'identification_number' => [
                'required', 'string', 'max:30',
                Rule::unique('employees', 'identification_number')->where('company_id', $companyId)->ignore($ignoreId),
            ],
            'ccss_number' => ['nullable', 'string', 'max:30'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name1' => ['required', 'string', 'max:255'],
            'last_name2' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:20'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],

            'hire_date' => ['required', 'date'],
            // La salida no puede ser anterior al ingreso: además de absurdo,
            // dejaría los días trabajados en negativo.
            'termination_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'termination_reason' => ['nullable', 'string', 'max:40'],
            // Se conservan las columnas de texto además del catálogo: hay
            // fichas con el departamento escrito a mano, y quitarlas
            // perdería ese dato antes de que alguien lo pueda mapear.
            'position' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'job_position_id' => ['nullable', Rule::exists('job_positions', 'id')->where('company_id', $companyId)],
            'cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')->where('company_id', $companyId)],
            'salary_expense_account_id' => [
                'nullable',
                Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId),
            ],
            'contract_type' => ['required', Rule::in(['indefinido', 'plazo_fijo', 'obra_determinada', 'ocasional'])],
            'journey_type' => ['required', Rule::in(['diurna', 'mixta', 'nocturna'])],
            'weekly_hours' => ['required', 'numeric', 'gt:0', 'max:168'],
            // Solo pesa en el salario semanal, pero se guarda siempre: entre 6
            // y 7 hay un 16% de diferencia en el valor del día, y ese es el
            // número que multiplica los días de una liquidación.
            'weekly_salary_divisor' => ['nullable', 'integer', Rule::in([6, 7])],

            'salary_type' => ['required', Rule::in(['mensual', 'quincenal', 'semanal', 'diario', 'hora'])],
            'base_salary' => ['required', 'numeric', 'gte:0'],

            'payment_method' => ['required', Rule::in(['transferencia', 'cheque', 'efectivo'])],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:34'],

            'has_spouse_credit' => ['boolean'],
            'children_credit_count' => ['integer', 'min:0', 'max:30'],
            'is_income_tax_exempt' => ['boolean'],
            'is_ccss_exempt' => ['boolean'],
            // Distinto de la exención total: un pensionado sí cotiza SEM y
            // Banco Popular (6,50%); lo que ya no cotiza es IVM.
            'is_pensioner' => ['boolean'],

            'status' => ['required', Rule::in(['active', 'suspended', 'terminated'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Las casillas llegan ausentes cuando están desmarcadas: sin el `?? false`
     * una actualización que las apague no cambiaría nada.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalize(array $validated): array
    {
        return [
            ...$validated,
            'has_spouse_credit' => $validated['has_spouse_credit'] ?? false,
            'is_income_tax_exempt' => $validated['is_income_tax_exempt'] ?? false,
            'is_ccss_exempt' => $validated['is_ccss_exempt'] ?? false,
            'is_pensioner' => $validated['is_pensioner'] ?? false,
            'children_credit_count' => $validated['children_credit_count'] ?? 0,
            // Con fecha de salida, el trabajador queda dado de baja sin que
            // nadie tenga que acordarse de cambiar el estado. Olvidarlo lo
            // dejaría apareciendo como activo en la verificación previa y en
            // la lista de la planilla siguiente.
            //
            // No opera al revés: borrar la fecha NO lo reactiva. Una
            // reincorporación es una decisión con su propia acción de
            // personal, no la consecuencia de vaciar un campo.
            'status' => ($validated['termination_date'] ?? null) !== null
                ? 'terminated'
                : $validated['status'],
        ];
    }
}
