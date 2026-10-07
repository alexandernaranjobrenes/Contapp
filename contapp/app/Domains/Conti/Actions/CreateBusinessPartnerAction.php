<?php

namespace App\Domains\Conti\Actions;

use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Models\User;

final class CreateBusinessPartnerAction extends BusinessPartnerAction
{
    public function key(): string
    {
        return 'crear_socio';
    }

    public function label(): string
    {
        return 'Crear un socio de negocio';
    }

    public function description(): string
    {
        return 'Da de alta un cliente o proveedor. El correo y el teléfono se agregan después en su ficha.';
    }

    public function fields(): array
    {
        return $this->partnerFields();
    }

    public function form(Company $company): array
    {
        return $this->partnerForm($company);
    }

    public function suggest(array $values, Company $company): array
    {
        return $this->partnerSuggestions($values);
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        // Como en la pantalla: sin fecha, el socio arranca hoy.
        $input['socio_desde'] = ($input['socio_desde'] ?? '') !== '' ? $input['socio_desde'] : now()->format('Y-m-d');
        $columns = $this->normalize($input, $company, null);

        return new PreparedAction(
            title: "Crear el socio {$columns['code']} — {$columns['name']}",
            items: $this->describe($columns),
            payload: $columns,
            input: $input,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        $partner = BusinessPartner::create($prepared->payload);

        return new ActionResult("Socio {$partner->code} creado.", route('business-partners.edit', $partner->id), 'Ver la ficha del socio');
    }
}
