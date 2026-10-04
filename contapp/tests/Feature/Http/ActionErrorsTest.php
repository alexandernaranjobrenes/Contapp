<?php

use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Errores de una acción: se dicen en pantalla (CLAUDE.md secc. 27)
|--------------------------------------------------------------------------
|
| Una acción de Inertia que falla vuelve a la pantalla con el motivo como
| mensaje, y una descarga que falla contesta en JSON para que el navegador
| lo muestre (ver bootstrap/app.php y resources/js/Utils/downloads.js).
|
*/

/** Lo que manda Inertia en cada visita. */
function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];
}

/** Superusuario de una compañía cuya licencia ya venció (modo de gracia). */
function graceModeOwner(): array
{
    $activation = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Empresa Vencida'],
        ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
    );
    $activation['license']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    test()->actingAs($activation['user']);

    return $activation;
}

it('una acción de Inertia que el servidor rechaza vuelve a la pantalla con el motivo como mensaje', function () {
    graceModeOwner();

    $this->from(route('appearance.edit'))
        ->put(route('appearance.update'), ['theme' => 'esmeralda'], inertiaHeaders())
        ->assertStatus(303)
        ->assertRedirect(route('appearance.edit'))
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'La licencia de tu compañía está vencida'));
});

it('sin un motivo propio, dice que no hay permiso, en español', function () {
    ['user' => $user] = logInAsCompanyUser();
    $other = User::factory()->create();

    // Un Usuario común que intenta gestionar a otro: el middleware lo frena.
    $this->from(route('dashboard'))
        ->post(route('users.deactivate', $other->id), [], inertiaHeaders())
        ->assertStatus(303)
        ->assertSessionHas('error', 'No tenés permiso para hacer esto.');
});

it('algo que ya no existe se dice como tal, sin llevarse la pantalla', function () {
    logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->from(route('dashboard'))
        ->delete(route('warehouses.destroy', 999999), [], inertiaHeaders())
        ->assertStatus(303)
        ->assertSessionHas('error', 'No encontramos lo que buscabas. Puede que ya no exista.');
});

it('sin Inertia, o en una visita GET, el error sigue siendo el de siempre', function () {
    graceModeOwner();

    // Sin la cabecera de Inertia: el 403 de siempre (lo que ven los tests y la API).
    $this->put(route('appearance.update'), ['theme' => 'esmeralda'])->assertForbidden();

    // Una visita GET no vuelve atrás: podría ser ella misma, y entraría en un
    // bucle. Esa la avisa el navegador.
    $this->get('/no-existe-esta-pantalla', inertiaHeaders())->assertNotFound();
});

it('los errores de validación no cambian: vuelven con cada error en su campo', function () {
    logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->from(route('appearance.edit'))
        ->put(route('appearance.update'), ['theme' => 'no-existe'], inertiaHeaders())
        ->assertRedirect(route('appearance.edit'))
        ->assertSessionHasErrors('theme')
        ->assertSessionMissing('error');
});

// ── Descargas ────────────────────────────────────────────────────────────

it('una descarga pedida por la aplicación contesta sus errores en JSON, con el motivo', function () {
    logInAsCompanyUser(null, ['is_super_admin' => true]);

    // Una fecha que no es fecha: el reporte no se puede armar.
    $this->get(route('reports.trial-balance.export', ['from' => 'no-es-una-fecha']), ['X-Contapp-Download' => '1'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);

    // Una que sí se puede: el archivo, como siempre.
    $this->get(route('reports.trial-balance.export', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]), ['X-Contapp-Download' => '1'])
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('una descarga sin permiso contesta 403 en JSON', function () {
    // Un Usuario común sin ningún módulo otorgado.
    $user = User::factory()->create();
    ['company' => $company] = logInAsCompanyUser();
    $company->users()->attach($user->id, ['is_default' => true]);
    $user->forceFill(['default_company_id' => $company->id])->save();
    $this->actingAs($user);

    $this->get(route('reports.trial-balance.export'), ['X-Contapp-Download' => '1'])
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});
