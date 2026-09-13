<?php

use App\Domains\Licensing\Models\License;
use App\Domains\Core\Models\Company;
use App\Models\User;

it('rechaza activar con un código que no existe', function () {
    $this->post(route('license-activation.store'), [
        'code' => 'CONTAPP-NOPE-NOPE-NOPE',
        'legal_name' => 'Mi Empresa',
        'name' => 'Juan Pérez',
        'email' => 'juan@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('activa una licencia válida, crea la compañía y deja al usuario logueado como super usuario de su propia compañía', function () {
    $license = License::factory()->create(['max_companies' => 1]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Mi Empresa S.A.',
        'trade_name' => 'Mi Empresa',
        'tax_id' => '3-101-999999',
        'name' => 'Juan Pérez',
        'email' => 'juan@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertRedirect(route('dashboard'));

    $user = User::where('email', 'juan@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->isSuperAdmin())->toBeTrue();

    $company = Company::where('legal_name', 'Mi Empresa S.A.')->sole();
    expect($company->license_id)->toBe($license->id);
});

it('rechaza activar una licencia que ya alcanzó el máximo de compañías', function () {
    $license = License::factory()->create(['max_companies' => 1]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Primera Empresa',
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ]);

    // El primer activate() dejó al cliente de pruebas autenticado como Ana;
    // /activate está bajo middleware 'guest', así que sin cerrar sesión el
    // segundo intento ni siquiera llegaría al controlador (rebotaría antes).
    $this->delete(route('logout'));

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Segunda Empresa',
        'name' => 'Beto',
        'email' => 'beto@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertSessionHasErrors('code');

    expect(User::where('email', 'beto@example.com')->exists())->toBeFalse();
});

it('rechaza activar una licencia vencida', function () {
    $license = License::factory()->expired()->create();

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Mi Empresa',
        'name' => 'Juan',
        'email' => 'juan@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('cierra la sesión de inmediato si la licencia de la compañía fue revocada', function () {
    $license = License::factory()->create();

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Mi Empresa',
        'name' => 'Juan',
        'email' => 'juan@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ]);

    $license->update(['status' => 'revoked']);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('una licencia solo vencida (no revocada) entra en modo de gracia: no bloquea la sesión, pero impide escribir', function () {
    $license = License::factory()->create();

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Mi Empresa',
        'name' => 'Juan',
        'email' => 'juan@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ]);

    // La licencia vence después de la activación (ej. pasó un año sin renovar).
    $license->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    // Consultar sigue funcionando — nada de logout ni redirección a login.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('licenseGrace', true));

    // Crear/modificar sí se bloquea, sin llegar siquiera a validar el body.
    $this->post(route('chart-of-accounts.store'), [])->assertForbidden();

    // Cambiar de compañía activa y cerrar sesión siguen exentos del bloqueo
    // (housekeeping de sesión, no dato de negocio) — mismo usuario, misma
    // única compañía, así que company-switch es un no-op válido.
    $company = Company::where('legal_name', 'Mi Empresa')->sole();
    $this->put(route('company-switch'), ['company_id' => $company->id])->assertSessionHasNoErrors();
    $this->delete(route('logout'))->assertRedirect(route('login'));
});

it('cambiar a una segunda compañía "pega" en el siguiente request, con el id llegando como string (igual que un <select> real)', function () {
    // Regresión: SetCurrentCompany comparaba $company->id (int, vía Eloquent)
    // contra el id guardado en sesión con === estricto. event.target.value de
    // un <select> HTML SIEMPRE es string, y la regla de validación 'integer'
    // de Laravel no lo castea — la comparación fallaba SIEMPRE, así que el
    // cambio de compañía nunca "pegaba" y cada request volvía a caer en
    // default_company_id. En un test con $company->id (ya int) el bug no se
    // ve — por eso acá se fuerza (string) explícitamente, para reproducir lo
    // que realmente manda el navegador.
    $license = License::factory()->create(['max_companies' => 2]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Empresa Uno',
        'name' => 'Juan',
        'email' => 'juan@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ]);

    $this->post(route('companies.store'), ['legal_name' => 'Empresa Dos'])->assertSessionHasNoErrors();

    $companyOne = Company::where('legal_name', 'Empresa Uno')->sole();
    $companyTwo = Company::where('legal_name', 'Empresa Dos')->sole();

    $this->put(route('company-switch'), ['company_id' => (string) $companyTwo->id])
        ->assertSessionHasNoErrors();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('currentCompanyId', $companyTwo->id));

    // Y no se quedó pegado ahí por casualidad: otro switch, a la otra, también pega.
    $this->put(route('company-switch'), ['company_id' => (string) $companyOne->id])
        ->assertSessionHasNoErrors();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('currentCompanyId', $companyOne->id));
});

it('un propietario puede emitir, renovar, suspender, reactivar y revocar licencias', function () {
    loginAsPropietario();

    $category = \App\Domains\Licensing\Models\LicenseCategory::factory()->create(['max_companies' => 3]);

    $this->post(route('backoffice.licenses.store'), [
        'category_id' => $category->id,
        'expires_at' => now()->addYear()->format('Y-m-d'),
        'notes' => 'Cliente de prueba',
    ])->assertSessionHasNoErrors();

    $license = License::sole();
    expect($license->max_companies)->toBe(3)
        ->and($license->category_id)->toBe($category->id);

    $this->post(route('backoffice.licenses.renew', $license->id), [
        'expires_at' => now()->addYears(2)->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    expect($license->fresh()->expires_at->format('Y'))->toBe(now()->addYears(2)->format('Y'));

    $this->post(route('backoffice.licenses.suspend', $license->id))->assertSessionHasNoErrors();
    expect($license->fresh()->status)->toBe('suspended');

    $this->post(route('backoffice.licenses.reactivate', $license->id))->assertSessionHasNoErrors();
    expect($license->fresh()->status)->toBe('active');

    $this->post(route('backoffice.licenses.revoke', $license->id))->assertSessionHasNoErrors();

    expect($license->fresh()->status)->toBe('revoked');
});

it('rechaza el acceso al panel de licencias a un usuario de compañía: no es Propietario', function () {
    logInAsCompanyUser();

    $this->get(route('backoffice.licenses.index'))->assertRedirect(route('backoffice.login'));
});

it('activar una licencia la fija como superuser_id del usuario creado', function () {
    $license = License::factory()->create(['max_companies' => 1]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Mi Empresa',
        'name' => 'Juan Pérez',
        'email' => 'juan@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertRedirect(route('dashboard'));

    $user = User::where('email', 'juan@example.com')->sole();

    expect($license->fresh()->superuser_id)->toBe($user->id);
});

it('rechaza reactivar el mismo código aunque queden cupos disponibles: /activate es de una sola vez', function () {
    $license = License::factory()->create(['max_companies' => 3]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Primera Empresa',
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertRedirect(route('dashboard'));

    $this->delete(route('logout'));

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Segunda Empresa',
        'name' => 'Beto',
        'email' => 'beto@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertSessionHasErrors('code');

    expect(User::where('email', 'beto@example.com')->exists())->toBeFalse()
        ->and($license->fresh()->companies()->count())->toBe(1);
});

it('el superusuario dueño de la licencia agrega una compañía adicional bajo su misma cuenta, sin crear un usuario nuevo', function () {
    $license = License::factory()->create(['max_companies' => 2]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Primera Empresa',
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ]);

    $user = User::where('email', 'ana@example.com')->sole();

    $this->post(route('companies.store'), [
        'legal_name' => 'Segunda Empresa',
    ])->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and($user->companies()->count())->toBe(2);

    $secondCompany = Company::where('legal_name', 'Segunda Empresa')->sole();
    expect($secondCompany->license_id)->toBe($license->id);

    $pivot = $user->companies()->whereKey($secondCompany->id)->first()->pivot;
    expect((bool) $pivot->is_default)->toBeFalse();
});

it('rechaza agregar una compañía adicional cuando la licencia ya alcanzó su tope de cupo', function () {
    $license = License::factory()->create(['max_companies' => 1]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Primera Empresa',
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ]);

    $this->post(route('companies.store'), [
        'legal_name' => 'Segunda Empresa',
    ])->assertSessionHasErrors('legal_name');

    expect(Company::count())->toBe(1);
});

it('rechaza agregar una compañía adicional a un usuario que no es el superusuario dueño de la licencia', function () {
    $license = License::factory()->create(['max_companies' => 3]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Primera Empresa',
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ]);

    $superuser = User::where('email', 'ana@example.com')->sole();
    $company = Company::where('legal_name', 'Primera Empresa')->sole();

    $admin = app(\App\Domains\Core\Services\PermissionGrantService::class)->createUser(
        $superuser,
        $company,
        ['name' => 'Beto Admin', 'email' => 'beto-admin@example.com', 'password' => 'secreto123'],
        'admin',
        [],
    );

    $this->actingAs($admin);
    $this->withSession(['current_company_id' => $company->id]);

    $this->get(route('companies.create'))->assertForbidden();

    // store() atrapa NotLicenseSuperuserException igual que /activate atrapa
    // InvalidLicenseException: vuelve con un error de sesión, no un 403 crudo.
    $this->post(route('companies.store'), ['legal_name' => 'Tercera Empresa'])
        ->assertSessionHasErrors('legal_name');

    expect(Company::count())->toBe(1);
});

it('rechaza agregar una compañía adicional si la licencia todavía no tiene superuser_id (dato previo al backfill)', function () {
    $license = License::factory()->create(['max_companies' => 3]);
    $company = Company::factory()->create(['license_id' => $license->id]);
    $user = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    expect($license->superuser_id)->toBeNull();

    $this->actingAs($user);

    $this->get(route('companies.create'))->assertForbidden();
    $this->post(route('companies.store'), ['legal_name' => 'Otra Empresa'])
        ->assertSessionHasErrors('legal_name');

    expect(Company::count())->toBe(1);
});
