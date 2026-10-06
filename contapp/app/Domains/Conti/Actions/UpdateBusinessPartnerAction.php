<?php

namespace App\Domains\Conti\Actions;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Models\User;

final class UpdateBusinessPartnerAction extends BusinessPartnerAction
{
    public function key(): string
    {
        return 'actualizar_socio';
    }

    public function label(): string
    {
        return 'Actualizar un socio de negocio';
    }

    public function description(): string
    {
        return 'Cambia datos de un socio existente. Mandá «socio» (su código actual) y solo los campos que cambian; para quitar un valor opcional, mandalo vacío.';
    }

    public function fields(): array
    {
        return ['socio' => 'código actual del socio (obligatorio)']
            + $this->partnerFields()
            + ['estado' => 'activo | inactivo'];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $partner = $this->byCode(BusinessPartner::class, $input['socio'] ?? null, 'socio', 'un socio');
        $partner->loadMissing(['glAccount:id,code', 'category:id,code', 'costCenter:id,code', 'priceList:id,code', 'currency:id,code']);

        // Lo actual, con los valores que mandó el agente encima.
        $current = [
            'codigo' => $partner->code,
            'nombre' => $partner->name,
            'tipo' => $partner->type,
            'cedula' => $partner->tax_id,
            'encargado' => $partner->contact_name,
            'codigo_actividad' => $partner->economic_activity_code,
            'socio_desde' => $partner->partner_since?->format('Y-m-d'),
            'categoria' => $partner->category?->code,
            'centro_costo' => $partner->costCenter?->code,
            'cuenta_control' => $partner->glAccount?->code,
            'moneda' => $partner->currency?->code,
            'lista_precios' => $partner->priceList?->code,
            'limite_credito' => $partner->credit_limit,
            'plazo_dias' => $partner->payment_terms_days,
            'estado' => $partner->status,
        ];

        $changes = array_intersect_key($input, $current);

        if ($changes === []) {
            $this->fail('socio', 'No mandaste ningún campo para cambiar.');
        }

        $before = $this->normalize($current, $company, $partner->id);
        $after = $this->normalize(array_merge($current, $changes), $company, $partner->id);

        $beforeItems = collect($this->describe($before))->pluck('valor', 'campo');
        $afterItems = collect($this->describe($after))->pluck('valor', 'campo');

        $items = [];
        foreach ($afterItems->keys()->merge($beforeItems->keys())->unique() as $label) {
            if (($beforeItems[$label] ?? null) !== ($afterItems[$label] ?? null)) {
                $items[] = $this->item($label, ($beforeItems[$label] ?? '—').' → '.($afterItems[$label] ?? '—'));
            }
        }

        if ($items === []) {
            $this->fail('socio', 'Los datos que mandaste son iguales a los que ya tiene el socio.');
        }

        $changed = [];
        foreach ($after as $column => $value) {
            if ($value !== $before[$column]) {
                $changed[$column] = $value;
            }
        }

        return new PreparedAction(
            title: "Actualizar el socio {$partner->code} — {$partner->name}",
            items: $items,
            payload: ['id' => $partner->id, 'columns' => $changed],
            input: ['socio' => $input['socio']] + $changes,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        $partner = BusinessPartner::findOrFail($prepared->payload['id']);
        $partner->update($prepared->payload['columns']);

        return new ActionResult("Socio {$partner->code} actualizado.", route('business-partners.edit', $partner->id), 'Ver la ficha del socio');
    }
}
