<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Licensing\Exceptions\AccountNotEligibleException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
|--------------------------------------------------------------------------
| Una cuenta que ya existe activa su propia licencia
|--------------------------------------------------------------------------
|
| Quien es Administrador o Usuario en la licencia de otra persona puede ser,
| con la misma cuenta, Superusuario de la suya. Lo que no puede es tener dos.
|
*/

/**
 * El punto de partida: la licencia de otra persona, ya activada, y una cuenta
 * («Kevin») que su Superusuario dio de alta con una contraseña que le puso él.
 *
 * $ownPassword: si Kevin ya eligió su propia contraseña por correo. Sin eso
 * no puede activar una licencia.
 */
function memberOfAnotherLicense(string $roleType = 'user', bool $ownPassword = true): array
{
    $other = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Empresa de Otra Persona'],
        ['name' => 'Dueña', 'email' => 'duena@example.com', 'password' => 'Secreto#1234'],
    );

    $member = app(PermissionGrantService::class)->createUser(
        $other['user'], $other['company'],
        ['name' => 'Kevin', 'email' => 'kevin@example.com', 'password' => 'Clave-Puesta-1234'],
        $roleType, [],
    );

    if ($ownPassword) {
        $member->forceFill(['password_chosen_at' => now()])->save();
    }

    return [
        'otherLicense' => $other['license'],
        'otherCompany' => $other['company'],
        'otherOwner' => $other['user'],
        'member' => $member->fresh(),
    ];
}

/** Lo que manda el formulario público con «Ya tengo una cuenta». */
function existingAccountPayload(License $license, array $overrides = []): array
{
    return array_merge([
        'account' => 'existing',
        'code' => $license->code,
        'legal_name' => 'Empresa de Kevin S.A.',
        'email' => 'kevin@example.com',
        'password' => 'Clave-Puesta-1234',
    ], $overrides);
}

// ── Por la puerta pública, con «Ya tengo una cuenta» ─────────────────────

it('una cuenta existente activa su licencia y queda como Superusuario de la suya sin dejar su rol en la otra', function (string $roleType) {
    $f = memberOfAnotherLicense($roleType);
    $member = $f['member'];
    $license = License::factory()->create();
    $usersBefore = User::count();

    $this->post(route('license-activation.store'), existingAccountPayload($license))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');

    $company = Company::where('legal_name', 'Empresa de Kevin S.A.')->sole();
    $member->refresh();

    // La licencia queda a su nombre, y no se creó ninguna cuenta.
    expect($license->fresh()->superuser_id)->toBe($member->id)
        ->and($company->license_id)->toBe($license->id)
        ->and(User::count())->toBe($usersBefore);

    // Superusuario en la suya; en la otra, lo que ya era.
    expect($member->isSuperAdmin($company->id))->toBeTrue()
        ->and($member->isSuperAdmin($f['otherCompany']->id))->toBeFalse()
        ->and($member->roleTypeFor($f['otherCompany']->id))->toBe($roleType)
        ->and($f['otherLicense']->fresh()->superuser_id)->toBe($f['otherOwner']->id);

    // Su cuenta no cambió: mismo nombre, misma contraseña, y la bandera
    // global de Superusuario sigue apagada.
    expect($member->name)->toBe('Kevin')
        ->and(Hash::check('Clave-Puesta-1234', $member->password))->toBeTrue()
        ->and((bool) $member->is_super_admin)->toBeFalse();

    // La compañía propia pasa a ser la predeterminada; la otra sigue activa.
    $memberships = $member->companies()->get()->keyBy('id');
    expect($member->default_company_id)->toBe($company->id)
        ->and((bool) $memberships[$company->id]->pivot->is_default)->toBeTrue()
        ->and((bool) $memberships[$f['otherCompany']->id]->pivot->is_default)->toBeFalse()
        ->and($memberships[$f['otherCompany']->id]->pivot->status)->toBe('active');

    // Entra directo, parado en su compañía.
    $this->assertAuthenticatedAs($member);
    expect(session('current_company_id'))->toBe($company->id);

    $log = AuditLog::where('action', 'license_activated')->sole();
    expect($log->user_id)->toBe($member->id)->and($log->company_id)->toBe($company->id);
})->with(['user', 'admin']);

it('no activa nada con la contraseña equivocada ni con un correo sin cuenta, y responde lo mismo', function (array $overrides) {
    memberOfAnotherLicense();
    $license = License::factory()->create();
    $companiesBefore = Company::count();

    $this->post(route('license-activation.store'), existingAccountPayload($license, $overrides))
        ->assertSessionHasErrors(['email' => 'Las credenciales no coinciden con ningún registro.']);

    expect($license->fresh()->superuser_id)->toBeNull()
        ->and(Company::count())->toBe($companiesBefore);
    $this->assertGuest();
})->with([
    'contraseña equivocada' => [['password' => 'no-es-esta']],
    'correo sin cuenta' => [['email' => 'nadie@example.com']],
]);

it('sin un código válido no sirve para probar contraseñas ni para saber qué correos tienen cuenta', function (array $payload) {
    memberOfAnotherLicense();

    $response = $this->post(route('license-activation.store'), array_merge([
        'code' => 'CONTAPP-NOPE-NOPE-NOPE',
        'legal_name' => 'Empresa de Kevin S.A.',
        'email' => 'kevin@example.com',
    ], $payload));

    // Solo se queja del código: del correo y de la contraseña no dice nada.
    $response->assertSessionHasErrors('code')->assertSessionDoesntHaveErrors(['email', 'password']);
})->with([
    'ya tengo una cuenta, contraseña correcta' => [['account' => 'existing', 'password' => 'Clave-Puesta-1234']],
    'ya tengo una cuenta, contraseña equivocada' => [['account' => 'existing', 'password' => 'no-es-esta']],
    'cuenta nueva con un correo que ya existe' => [['name' => 'Otro', 'password' => 'Secreto#1234', 'password_confirmation' => 'Secreto#1234']],
]);

it('frena los intentos repetidos de contraseña', function () {
    memberOfAnotherLicense();
    $license = License::factory()->create();
    $wrong = existingAccountPayload($license, ['password' => 'no-es-esta']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('license-activation.store'), $wrong)
            ->assertSessionHasErrors(['email' => 'Las credenciales no coinciden con ningún registro.']);
    }

    // Al sexto ni siquiera prueba la contraseña, aunque ahora sea la correcta.
    $this->post(route('license-activation.store'), existingAccountPayload($license))
        ->assertSessionHasErrors('email');

    expect($license->fresh()->superuser_id)->toBeNull();
});

it('crear una cuenta nueva con un correo que ya existe manda a «Ya tengo una cuenta», sin tocar esa cuenta', function () {
    $f = memberOfAnotherLicense();
    $license = License::factory()->create();

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Empresa de Kevin S.A.',
        'name' => 'Un Impostor',
        'email' => 'kevin@example.com',
        'password' => 'Secreto#1234',
        'password_confirmation' => 'Secreto#1234',
    ])->assertSessionHasErrors('email');

    $member = $f['member']->fresh();

    expect($license->fresh()->superuser_id)->toBeNull()
        ->and($member->name)->toBe('Kevin')
        ->and(Hash::check('Clave-Puesta-1234', $member->password))->toBeTrue();
    $this->assertGuest();
});

// ── Una cuenta, una licencia ─────────────────────────────────────────────

it('una cuenta que ya es dueña de una licencia no puede activar otra', function () {
    $f = memberOfAnotherLicense();
    $second = License::factory()->create();

    $this->post(route('license-activation.store'), existingAccountPayload($second, [
        'email' => 'duena@example.com',
        'password' => 'Secreto#1234',
    ]))->assertSessionHasErrors('email');

    expect($second->fresh()->superuser_id)->toBeNull()
        ->and(License::where('superuser_id', $f['otherOwner']->id)->count())->toBe(1);
});

it('la base tampoco deja que una cuenta figure como dueña de dos licencias', function () {
    $f = memberOfAnotherLicense();
    $second = License::factory()->create();

    expect(fn () => $second->forceFill(['superuser_id' => $f['otherOwner']->id])->save())
        ->toThrow(QueryException::class);

    // Las licencias sin activar no chocan entre sí.
    expect(License::factory()->count(2)->create()->pluck('superuser_id')->all())->toBe([null, null]);
});

// ── La contraseña tiene que ser de la persona ────────────────────────────

it('una cuenta con la contraseña que le puso otra persona no activa una licencia hasta elegir la suya', function () {
    $f = memberOfAnotherLicense(ownPassword: false);
    $member = $f['member'];
    $license = License::factory()->create();

    expect($member->password_chosen_at)->toBeNull();

    $this->post(route('license-activation.store'), existingAccountPayload($license))
        ->assertSessionHasErrors('email');
    expect($license->fresh()->superuser_id)->toBeNull();

    // La elige con el enlace que llega a su correo…
    $this->post(route('password.update'), [
        'token' => Password::broker('users')->createToken($member),
        'email' => $member->email,
        'password' => 'Propia-Clave-9876',
        'password_confirmation' => 'Propia-Clave-9876',
    ])->assertRedirect(route('login'));

    expect($member->fresh()->password_chosen_at)->not->toBeNull();

    // …y ahora sí.
    $this->post(route('license-activation.store'), existingAccountPayload($license, ['password' => 'Propia-Clave-9876']))
        ->assertRedirect(route('dashboard'));

    expect($license->fresh()->superuser_id)->toBe($member->id);
});

it('quien activa creando su cuenta eligió su contraseña; a quien le crean la cuenta, no', function () {
    $f = memberOfAnotherLicense(ownPassword: false);

    expect($f['otherOwner']->fresh()->password_chosen_at)->not->toBeNull()
        ->and($f['member']->password_chosen_at)->toBeNull();
});

it('el servicio dice por qué una cuenta no puede ser dueña', function () {
    $f = memberOfAnotherLicense(ownPassword: false);
    $service = app(LicenseActivationService::class);

    $reason = function (User $user) use ($service) {
        try {
            $service->assertCanOwnLicense($user);
        } catch (AccountNotEligibleException $e) {
            return $e->reason;
        }

        return null;
    };

    expect($reason($f['member']))->toBe(AccountNotEligibleException::PASSWORD_NOT_CHOSEN)
        ->and($reason($f['otherOwner']))->toBe(AccountNotEligibleException::ALREADY_OWNER);

    $f['member']->forceFill(['password_chosen_at' => now()])->save();
    expect($reason($f['member']))->toBeNull();

    $f['member']->forceFill(['status' => 'inactive'])->save();
    expect($reason($f['member']))->toBe(AccountNotEligibleException::INACTIVE);
});

// ── Con la sesión iniciada: Administración → Activar una licencia ────────

it('la pantalla interna exige sesión', function () {
    $this->get(route('license-redemption.create'))->assertRedirect(route('login'));
    $this->post(route('license-redemption.store'), [])->assertRedirect(route('login'));
});

it('la pantalla interna muestra el formulario, el aviso de contraseña o que ya tiene licencia, según la cuenta', function () {
    $f = memberOfAnotherLicense(ownPassword: false);

    // Con la contraseña que le puso otra persona: primero tiene que elegir la suya.
    $this->actingAs($f['member']);
    $this->get(route('license-redemption.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Licenses/Redeem')
            ->where('account.email', 'kevin@example.com')
            ->where('ownedLicense', null)
            ->where('passwordChosen', false)
            ->where('auth.user.owns_license', false));

    // Con contraseña propia: el formulario.
    $f['member']->forceFill(['password_chosen_at' => now()])->save();
    $this->get(route('license-redemption.create'))
        ->assertInertia(fn ($page) => $page->where('ownedLicense', null)->where('passwordChosen', true));

    // Quien ya es dueña de una: no hay nada que canjear.
    $this->actingAs($f['otherOwner']);
    $this->get(route('license-redemption.create'))
        ->assertInertia(fn ($page) => $page
            ->where('ownedLicense.masked_code', $f['otherLicense']->maskedCode())
            ->where('auth.user.owns_license', true));
});

it('con la sesión iniciada activa su licencia, y el rol depende de la compañía en la que esté parado', function () {
    $f = memberOfAnotherLicense();
    $member = $f['member'];
    $license = License::factory()->create(['max_companies' => 2]);

    $this->actingAs($member);

    // Antes: Usuario en la compañía de la otra licencia, sin licencia propia.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('auth.user.role_type', 'user')
        ->where('auth.user.owns_license', false));

    $this->post(route('license-redemption.store'), [
        'code' => $license->code,
        'legal_name' => 'Empresa de Kevin S.A.',
    ])->assertRedirect(route('dashboard'))->assertSessionHas('success');

    $company = Company::where('legal_name', 'Empresa de Kevin S.A.')->sole();
    expect($license->fresh()->superuser_id)->toBe($member->id)
        ->and($member->fresh()->default_company_id)->toBe($company->id);

    // Queda parado en la suya, como Superusuario.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('currentCompanyId', $company->id)
        ->where('auth.user.is_super_admin', true)
        ->where('auth.user.role_type', 'super_admin')
        ->where('auth.user.owns_license', true));

    // Desde la suya puede agregar compañías a su licencia.
    $this->get(route('companies.create'))->assertOk();

    // Al pasar a la de la otra persona vuelve a ser Usuario: ni Superusuario
    // ni «Agregar compañía», pero sigue siendo dueño de su licencia.
    $this->put(route('company-switch'), ['company_id' => $f['otherCompany']->id]);
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('currentCompanyId', $f['otherCompany']->id)
        ->where('auth.user.is_super_admin', false)
        ->where('auth.user.role_type', 'user')
        ->where('auth.user.owns_license', true));
    $this->get(route('companies.create'))->assertForbidden();
    $this->post(route('companies.store'), ['legal_name' => 'Colada'])->assertSessionHasErrors('legal_name');

    expect($f['otherLicense']->fresh()->companies()->count())->toBe(1);
});

it('con la sesión iniciada tampoco activa una segunda licencia ni con la contraseña de otro', function (bool $ownPassword, string $who) {
    $f = memberOfAnotherLicense(ownPassword: $ownPassword);
    $license = License::factory()->create();

    $this->actingAs($f[$who]);

    $this->post(route('license-redemption.store'), [
        'code' => $license->code,
        'legal_name' => 'No Debería Existir',
    ])->assertSessionHasErrors('account');

    expect($license->fresh()->superuser_id)->toBeNull()
        ->and(Company::where('legal_name', 'No Debería Existir')->exists())->toBeFalse();
})->with([
    'ya es dueña de una licencia' => [true, 'otherOwner'],
    'tiene la contraseña que le puso otra persona' => [false, 'member'],
]);

it('con la sesión iniciada rechaza un código inválido', function () {
    $f = memberOfAnotherLicense();
    $this->actingAs($f['member']);

    $this->post(route('license-redemption.store'), [
        'code' => 'CONTAPP-NOPE-NOPE-NOPE',
        'legal_name' => 'Empresa de Kevin S.A.',
    ])->assertSessionHasErrors('code');

    // Un código ya activado tampoco: el de la licencia en la que es Usuario.
    $this->post(route('license-redemption.store'), [
        'code' => $f['otherLicense']->code,
        'legal_name' => 'Empresa de Kevin S.A.',
    ])->assertSessionHasErrors('code');

    expect($f['otherLicense']->fresh()->superuser_id)->toBe($f['otherOwner']->id);
});

it('que la licencia de la otra persona esté vencida no le impide activar la suya', function () {
    $f = memberOfAnotherLicense();
    $license = License::factory()->create();

    $f['otherLicense']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);
    $this->actingAs($f['member']);

    // Está en modo de gracia en la compañía de la otra licencia…
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('licenseGrace', true));
    $this->post(route('chart-of-accounts.store'), [])->assertForbidden();

    // …pero activar la suya no es escribir en esa compañía.
    $this->post(route('license-redemption.store'), [
        'code' => $license->code,
        'legal_name' => 'Empresa de Kevin S.A.',
    ])->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('licenseGrace', false));
});

it('desde adentro pide el enlace para elegir su contraseña, y al elegirla se cierra esa sesión', function () {
    Notification::fake();
    $f = memberOfAnotherLicense(ownPassword: false);
    $member = $f['member'];
    $this->actingAs($member);

    $this->post(route('license-redemption.password-link'))->assertSessionHas('success');
    Notification::assertSentToTimes($member, ResetPasswordNotification::class, 1);

    // Un segundo pedido enseguida no manda otro correo, y lo dice.
    $this->post(route('license-redemption.password-link'))->assertSessionHas('error');
    Notification::assertSentToTimes($member, ResetPasswordNotification::class, 1);

    // El enlace se abre con la sesión iniciada.
    $token = Password::broker('users')->createToken($member);
    $this->get(route('password.reset', ['token' => $token, 'email' => $member->email]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword')->where('valid', true));

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $member->email,
        'password' => 'Propia-Clave-9876',
        'password_confirmation' => 'Propia-Clave-9876',
    ])->assertRedirect(route('login'))->assertSessionHas('success');

    $this->assertGuest();
    expect($member->fresh()->password_chosen_at)->not->toBeNull();
});

// ── El otro sentido: el dueño de una licencia, invitado a la de otro ─────

it('el Superusuario de una licencia puede ser invitado como Administrador a la de otra persona', function () {
    $f = memberOfAnotherLicense();

    $guest = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Empresa del Invitado'],
        ['name' => 'Invitado', 'email' => 'invitado@example.com', 'password' => 'Secreto#1234'],
    );

    $this->actingAs($f['otherOwner']);

    // Se lo invita, y entra cuando acepta (CompanyInvitationService).
    $this->post(route('users.store'), [
        'email' => 'invitado@example.com',
        'role_type' => 'admin',
        'permissions' => [],
    ])->assertRedirect(route('users.index'));

    expect($guest['user']->fresh()->roleTypeFor($f['otherCompany']->id))->toBeNull();

    app(\App\Domains\Core\Services\CompanyInvitationService::class)->accept(
        \App\Domains\Core\Models\CompanyInvitation::where('email', 'invitado@example.com')->sole(),
        null,
    );

    $invited = $guest['user']->fresh();

    expect($invited->roleTypeFor($f['otherCompany']->id))->toBe('admin')
        ->and($invited->isSuperAdmin($f['otherCompany']->id))->toBeFalse()
        ->and($invited->isSuperAdmin($guest['company']->id))->toBeTrue()
        ->and($guest['license']->fresh()->superuser_id)->toBe($invited->id)
        ->and($invited->default_company_id)->toBe($guest['company']->id)
        ->and(Hash::check('Secreto#1234', $invited->password))->toBeTrue();
});
