<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Conti\Resources\ContiFilters;
use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeNote;
use App\Models\User;

/** Una anotación en la bitácora de un trabajador (EmployeeNoteController::store). */
final class AddEmployeeNoteAction extends BaseContiAction
{
    public function key(): string
    {
        return 'anotar_empleado';
    }

    public function label(): string
    {
        return 'Anotar en la bitácora de un trabajador';
    }

    public function description(): string
    {
        return 'Registra un hecho, observación, reconocimiento o llamada de atención en la bitácora del trabajador. Una anotación no se edita después.';
    }

    public function screen(): string
    {
        return 'payroll.employees';
    }

    public function fields(): array
    {
        return [
            'empleado' => 'código del trabajador',
            'fecha' => 'AAAA-MM-DD del hecho; por defecto hoy',
            'categoria' => ContiFilters::options(EmployeeNote::CATEGORIES),
            'titulo' => 'título corto',
            'detalle' => 'el texto de la anotación',
            'confidencial' => 'sí | no (por defecto no)',
        ];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $input['fecha'] = ($input['fecha'] ?? '') !== '' ? $input['fecha'] : now()->format('Y-m-d');

        $this->validate($input, [
            'empleado' => ['required', 'string'],
            'fecha' => ['required', 'date'],
            'categoria' => ['required', 'string'],
            'titulo' => ['required', 'string', 'max:255'],
            'detalle' => ['required', 'string', 'max:5000'],
        ]);

        $employee = $this->byCode(Employee::class, $input['empleado'], 'empleado', 'un trabajador');
        $category = ContiFilters::keyFor((string) $input['categoria'], EmployeeNote::CATEGORIES);

        if (! array_key_exists($category, EmployeeNote::CATEGORIES)) {
            $this->fail('categoria', 'La categoría es una de: '.implode(', ', EmployeeNote::CATEGORIES).'.');
        }

        $date = date('Y-m-d', strtotime($input['fecha']));

        if ($employee->hire_date && $date < $employee->hire_date->format('Y-m-d')) {
            $this->fail('fecha', "La fecha no puede ser anterior al ingreso del trabajador ({$employee->hire_date->format('Y-m-d')}).");
        }

        $confidential = $this->bool($input['confidencial'] ?? null);

        return new PreparedAction(
            title: "Anotación para {$employee->code} {$employee->fullName()}",
            items: $this->items([
                'Trabajador' => "{$employee->code} {$employee->fullName()}",
                'Fecha del hecho' => $date,
                'Categoría' => EmployeeNote::CATEGORIES[$category],
                'Título' => $input['titulo'],
                'Detalle' => $input['detalle'],
                'Confidencial' => $confidential ? 'Sí' : 'No',
            ]),
            payload: [
                'employee_id' => $employee->id,
                'happened_on' => $date,
                'category' => $category,
                'title' => $input['titulo'],
                'body' => $input['detalle'],
                'is_confidential' => $confidential,
            ],
            input: $input,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        EmployeeNote::create([...$prepared->payload, 'company_id' => $company->id, 'created_by' => $user->id]);

        return new ActionResult('Anotación registrada en la bitácora.', route('employees.show', $prepared->payload['employee_id']), 'Ver la ficha del trabajador');
    }
}
