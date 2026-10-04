<?php

use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;

/*
|--------------------------------------------------------------------------
| Cambiar a una compañía según el estado de su licencia
|--------------------------------------------------------------------------
|
| Suspendida o revocada: no se entra, y se dice por qué (no un «el cambio no
| se aplicó» sin motivo). Vencida: se entra, en modo de gracia.
|
*/

/** Kevin, dueño de «Kevin Comercial», y además Usuario en «El Clavo», de otra licencia. */
function switchingUser(): array
{
    $activation = app(LicenseActivationService::class);

    $kevin = $activation->activate(License::factory()->create()->code, ['legal_name' => 'Kevin Comercial S.A.'],
        ['name' => 'Kevin', 'email' => 'kevin@example.com', 'password' => 'Secreto#1234']);
    $clavo = $activation->activate(License::factory()->create()->code, ['legal_name' => 'Ferretería El Clavo S.A.', 'trade_name' => 'El Clavo'],
        ['name' => 'Dueña', 'email' => 'duena@example.com', 'password' => 'Secreto#1234']);

    app(PermissionGrantService::class)->inviteUser($clavo['user'], $clavo['company'], 'kevin@example.com', 'user', []);

    test()->actingAs($kevin['user']);
    test()->get(route('dashboard'))->assertOk();

    return ['user' => $kevin['user'], 'own' => $kevin['company'], 'other' => $clavo['company'], 'otherLicense' => $clavo['license']];
}

it('a una compañía con la licencia revocada no se pasa, y se dice por qué', function () {
    ['own' => $own, 'other' => $other, 'otherLicense' => $license] = switchingUser();
    $license->update(['status' => 'revoked']);

    $this->put(route('company-switch'), ['company_id' => $other->id])
        ->assertSessionHasErrors(['license' => 'La licencia de El Clavo fue revocada, así que ya no se puede entrar a esa compañía. Si creés que es un error, contactá al equipo de CONTAPP.']);

    expect(session('current_company_id'))->toBe($own->id);
});

it('ni a una con la licencia suspendida', function () {
    ['own' => $own, 'other' => $other, 'otherLicense' => $license] = switchingUser();
    $license->update(['status' => 'suspended']);

    $this->put(route('company-switch'), ['company_id' => $other->id])
        ->assertSessionHasErrors(['license' => 'La licencia de El Clavo está suspendida: mientras siga así no se puede entrar a esa compañía. Para reactivarla, contactá al equipo de CONTAPP.']);

    expect(session('current_company_id'))->toBe($own->id);
});

it('a una con la licencia vencida sí se pasa, en modo de gracia', function () {
    ['other' => $other, 'otherLicense' => $license] = switchingUser();
    $license->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    $this->put(route('company-switch'), ['company_id' => $other->id])->assertSessionHasNoErrors();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('currentCompanyId', $other->id)
        ->where('licenseGrace', true));
});

it('el selector sabe el estado de la licencia de cada compañía', function () {
    ['own' => $own, 'other' => $other, 'otherLicense' => $license] = switchingUser();
    $license->update(['status' => 'revoked']);

    $companies = collect($this->get(route('dashboard'))->viewData('page')['props']['companies'])->keyBy('id');

    expect($companies[$own->id]['license_state'])->toBe('ok')
        ->and($companies[$other->id]['license_state'])->toBe('revoked');
});

it('si la licencia de la compañía en la que estaba se revoca, pasa a otra diciendo por qué', function () {
    ['own' => $own, 'other' => $other, 'otherLicense' => $license] = switchingUser();

    $this->put(route('company-switch'), ['company_id' => $other->id])->assertSessionHasNoErrors();
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('currentCompanyId', $other->id));

    $license->update(['status' => 'revoked']);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('currentCompanyId', $own->id)
        ->where('flash.error', 'La licencia de El Clavo fue revocada, así que ya no se puede entrar a esa compañía. Si creés que es un error, contactá al equipo de CONTAPP. Seguís en Kevin Comercial S.A.'));

    // Solo esa vez: en la siguiente visita ya no hay nada que avisar.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('flash.error', null));
});
