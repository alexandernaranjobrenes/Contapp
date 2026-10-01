<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\CompanyTheme;

/**
 * Administración → Apariencia (CLAUDE.md secc. 31): el tema visual de cada
 * compañía, que eligen su Superusuario y sus Administradores.
 */
function createCompanyMember($superAdmin, $company, string $roleType, string $email)
{
    $module = Module::where('code', 'accounting')->firstOrFail();

    return app(PermissionGrantService::class)->createUser(
        $superAdmin, $company,
        ['name' => 'Persona '.$roleType, 'email' => $email, 'password' => 'secreto123'],
        $roleType, [$module->id => 'read'],
    );
}

it('el superusuario ve los diez temas, con el predeterminado en uso', function () {
    logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Appearance/Index')
            ->has('themes', 10)
            ->where('current', CompanyTheme::default()->value));
});

it('el superusuario cambia el tema: se guarda, queda en la bitácora y lo recibe cada página', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->put(route('appearance.update'), ['theme' => 'esmeralda'])
        ->assertRedirect(route('appearance.edit'))
        ->assertSessionHas('success');

    expect($company->fresh()->theme)->toBe('esmeralda');

    $log = AuditLog::where('action', 'company.theme_updated')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->old_values)->toBe(['theme' => 'marino'])
        ->and($log->new_values)->toBe(['theme' => 'esmeralda']);

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('companyTheme', 'esmeralda')
            ->where('auth.user.can_manage_company', true));
});

it('un administrador de la compañía también puede cambiar el tema', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $admin = createCompanyMember($superAdmin, $company, 'admin', 'admin@example.com');

    $this->actingAs($admin);

    $this->get(route('appearance.edit'))->assertOk();
    $this->put(route('appearance.update'), ['theme' => 'onix'])->assertRedirect(route('appearance.edit'));

    expect($company->fresh()->theme)->toBe('onix');
});

it('un usuario común no ve ni cambia la apariencia', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $user = createCompanyMember($superAdmin, $company, 'user', 'usuario@example.com');

    $this->actingAs($user);

    $this->get(route('appearance.edit'))->assertForbidden();
    $this->put(route('appearance.update'), ['theme' => 'onix'])->assertForbidden();

    expect($company->fresh()->theme)->toBeNull();

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.can_manage_company', false));
});

it('rechaza un tema que no existe', function () {
    ['company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->put(route('appearance.update'), ['theme' => 'fucsia'])->assertSessionHasErrors('theme');

    expect($company->fresh()->theme)->toBeNull();
});

it('guardar el tema que ya estaba no deja entrada en la bitácora', function () {
    logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->put(route('appearance.update'), ['theme' => 'marino'])->assertRedirect(route('appearance.edit'));

    expect(AuditLog::where('action', 'company.theme_updated')->exists())->toBeFalse();
});

it('un tema guardado que ya no existe cae al predeterminado en vez de romper la página', function () {
    ['company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $company->forceFill(['theme' => 'retirado'])->save();

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('companyTheme', CompanyTheme::default()->value));
});

it('cada tema del catálogo tiene su paleta y su tipografía en la hoja de estilos', function () {
    $scss = file_get_contents(resource_path('css/app.scss'));
    $vite = file_get_contents(base_path('vite.config.js'));

    foreach (CompanyTheme::cases() as $theme) {
        expect($scss)->toContain("'{$theme->value}': (")
            ->and($vite)->toContain("'{$theme->font()}'");
    }
});
