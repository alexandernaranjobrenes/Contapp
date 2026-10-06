<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Models\User;

/** Un centro de costo, como en Centros de costo (CostCenterController::store). */
final class CreateCostCenterAction extends BaseContiAction
{
    public function key(): string
    {
        return 'crear_centro_costo';
    }

    public function label(): string
    {
        return 'Crear un centro de costo';
    }

    public function description(): string
    {
        return 'Da de alta un centro de costo. Para que reciba montos, después se incluye en una norma de reparto.';
    }

    public function screen(): string
    {
        return 'accounting.cost_centers';
    }

    public function fields(): array
    {
        return [
            'codigo' => 'código (no se puede cambiar después)',
            'nombre' => 'nombre',
            'vigente_desde' => 'AAAA-MM-DD; por defecto hoy',
            'vigente_hasta' => 'AAAA-MM-DD (opcional)',
            'activo' => 'sí | no (por defecto sí)',
        ];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $input['vigente_desde'] = ($input['vigente_desde'] ?? '') !== '' ? $input['vigente_desde'] : now()->format('Y-m-d');

        $this->validate($input, [
            'codigo' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:255'],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ]);

        if (CostCenter::where('code', $input['codigo'])->exists()) {
            $this->fail('codigo', "Ya existe un centro de costo con el código {$input['codigo']}.");
        }

        $payload = [
            'code' => $input['codigo'],
            'name' => $input['nombre'],
            'start_date' => date('Y-m-d', strtotime($input['vigente_desde'])),
            'end_date' => ($input['vigente_hasta'] ?? '') !== '' ? date('Y-m-d', strtotime($input['vigente_hasta'])) : null,
            'is_active' => $this->bool($input['activo'] ?? null, true),
        ];

        return new PreparedAction(
            title: "Crear el centro de costo {$payload['code']} — {$payload['name']}",
            items: $this->items([
                'Código' => $payload['code'],
                'Nombre' => $payload['name'],
                'Vigente desde' => $payload['start_date'],
                'Vigente hasta' => $payload['end_date'] ?? 'Sin fecha de fin',
                'Activo' => $payload['is_active'] ? 'Sí' : 'No',
            ]),
            payload: $payload,
            input: $input,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        CostCenter::create([...$prepared->payload, 'company_id' => $company->id]);

        return new ActionResult("Centro de costo {$prepared->payload['code']} creado.", route('cost-centers.index'), 'Ver los centros de costo');
    }
}
