<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| La bienvenida de una licencia recién activada (CLAUDE.md secc. 12)
|--------------------------------------------------------------------------
|
| Activar no pide los datos de la compañía: nace con el nombre predefinido.
| La primera vez que el dueño entra, un recorrido por Pivox (welcomeTour)
| termina pidiéndole los datos reales. Terminarlo o saltarlo lo apaga.
*/

/** Activa una licencia nueva por la puerta pública y devuelve la licencia. */
function activateWelcomeLicense(array $attributes = []): License
{
    $license = License::factory()->create(['max_companies' => 2, ...$attributes]);

    test()->post(route('license-activation.store'), [
        'code' => $license->code,
        'name' => 'Kevin Naranjo',
        'email' => 'kevin@example.com',
        'password' => 'Secreto#1234',
        'password_confirmation' => 'Secreto#1234',
    ])->assertRedirect(route('dashboard'));

    return $license->fresh();
}

it('el dueño de una licencia recién activada ve el recorrido, con todas las secciones y la compañía provisional', function () {
    activateWelcomeLicense();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('welcomeTour.company.legal_name', LicenseActivationService::DEFAULT_COMPANY_NAME)
        ->where('welcomeTour.company.trade_name', LicenseActivationService::DEFAULT_COMPANY_NAME)
        ->where('welcomeTour.sections', fn ($sections) => collect($sections)->sort()->values()->all() === [
            'accounting', 'banking', 'billing', 'business_partners', 'cost_fx', 'inventory', 'payroll', 'tax',
        ]));
});

it('terminarlo lo apaga para siempre, y «Cambiar los datos» lleva a la ficha de la compañía', function () {
    $license = activateWelcomeLicense();
    $company = Company::where('license_id', $license->id)->sole();

    $this->post(route('welcome-tour.complete'), ['edit_company' => true])
        ->assertRedirect(route('profile.edit', ['compania' => $company->id]));

    expect($license->fresh()->welcome_pending)->toBeFalse();

    $this->get(route('profile.edit', ['compania' => $company->id]))->assertInertia(fn ($page) => $page
        ->where('welcomeTour', null)
        ->where('openCompanyId', $company->id));

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('welcomeTour', null));
});

it('saltarlo también lo apaga y vuelve a la misma pantalla', function () {
    $license = activateWelcomeLicense();

    $this->from(route('dashboard'))->post(route('welcome-tour.complete'))->assertRedirect(route('dashboard'));

    expect($license->fresh()->welcome_pending)->toBeFalse();
});

it('quien entra invitado a la licencia no lo ve: es solo para su dueño', function () {
    $license = activateWelcomeLicense();
    $company = Company::where('license_id', $license->id)->sole();

    $admin = app(PermissionGrantService::class)->createUser(
        User::where('email', 'kevin@example.com')->sole(),
        $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
        'admin',
        [],
    );

    $this->actingAs($admin)->withSession(['current_company_id' => $company->id]);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('welcomeTour', null));

    // Y cerrarlo desde su cuenta no le apaga el del dueño.
    $this->post(route('welcome-tour.complete'));
    expect($license->fresh()->welcome_pending)->toBeTrue();
});

it('las licencias que ya estaban activas no lo ven', function () {
    $license = activateWelcomeLicense();
    $license->forceFill(['welcome_pending' => false])->save();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('welcomeTour', null));
});

it('con la licencia vencida igual se puede cerrar: no deja a nadie encerrado', function () {
    $license = activateWelcomeLicense();
    $license->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    $this->from(route('dashboard'))->post(route('welcome-tour.complete'))->assertRedirect(route('dashboard'));

    expect($license->fresh()->welcome_pending)->toBeFalse();
});

it('activar con una cuenta existente, o aceptando desde Mi cuenta, también deja la bienvenida pendiente', function () {
    $owner = User::factory()->create(['status' => 'active', 'password_chosen_at' => now()]);
    $license = License::factory()->create();

    app(LicenseActivationService::class)->activateForExistingUser($license->code, [], $owner);

    expect($license->fresh()->welcome_pending)->toBeTrue()
        ->and(Company::where('license_id', $license->id)->sole()->legal_name)->toBe(LicenseActivationService::DEFAULT_COMPANY_NAME);
});

it('«Mi cuenta» no abre la ficha de una compañía que la persona no puede editar', function () {
    $license = activateWelcomeLicense();
    $company = Company::where('license_id', $license->id)->sole();

    $admin = app(PermissionGrantService::class)->createUser(
        User::where('email', 'kevin@example.com')->sole(),
        $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
        'admin',
        [],
    );

    // El controlador manda el id tal cual; es la pantalla la que abre la
    // ficha solo si la membresía dice can_edit, y para un Administrador no.
    $this->actingAs($admin)->withSession(['current_company_id' => $company->id])
        ->get(route('profile.edit', ['compania' => $company->id]))
        ->assertInertia(fn ($page) => $page
            ->where('openCompanyId', $company->id)
            ->where('memberships.0.can_edit', false));

    // Y el recorrido no la manda a editar nada: vuelve a donde estaba.
    $this->from(route('dashboard'))
        ->post(route('welcome-tour.complete'), ['edit_company' => true])
        ->assertRedirect(route('dashboard'));
});
