<?php

use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Services\CompanyInvitationService;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Invitar a una persona a una compañía
|--------------------------------------------------------------------------
|
| Quien invita pone correo, rol y permisos por pantalla. La persona acepta
| desde el correo: sin cuenta, elige ahí su nombre y su contraseña; con
| cuenta, solo acepta. Hasta entonces no entra (CompanyInvitationService).
|
*/

beforeEach(fn () => Notification::fake());

const INVITEE_PASSWORD = 'Mi-Clave-Nueva-2468';

/** Una compañía con licencia y su Superusuario, con la sesión iniciada. */
function invitingOwner(array $license = []): array
{
    $activation = app(LicenseActivationService::class)->activate(
        License::factory()->create($license)->code,
        ['legal_name' => 'Ferretería El Clavo S.A.', 'trade_name' => 'El Clavo'],
        ['name' => 'Dueña', 'email' => 'duena@example.com', 'password' => 'Secreto#1234'],
    );

    test()->actingAs($activation['user']);

    return ['owner' => $activation['user'], 'company' => $activation['company'], 'license' => $activation['license']];
}

/** El token del último correo de invitación que le llegó a esa dirección. */
function companyInvitationToken(string $email): string
{
    $url = null;

    Notification::assertSentOnDemand(CompanyInvitationNotification::class, function ($notification, $channels, $notifiable) use ($email, &$url) {
        if ($notifiable->routes['mail'] !== $email) {
            return false;
        }

        $url = $notification->toMail($notifiable)->viewData['url'];

        return true;
    });

    return basename(parse_url($url, PHP_URL_PATH));
}

/**
 * Sin sesión, como quien abre el enlace en otro navegador. También se olvida
 * la compañía activa: en un test las requests comparten el contenedor y
 * quedaría la de quien invitó, cosa que en la aplicación real no pasa (así se
 * escondió que aceptar dependía de la compañía activa).
 */
function asGuest(): void
{
    Auth::guard('web')->logout();
    session()->flush();
    app()->forgetInstance(CurrentCompany::class);
}

it('invita a alguien sin cuenta: nada se crea hasta que acepta', function () {
    ['company' => $company] = invitingOwner();

    $this->post(route('users.store'), [
        'email' => 'nueva@example.com',
        'role_type' => 'user',
        'permissions' => ['accounting.journal_entries' => 'read'],
    ])->assertRedirect(route('users.index'));

    Notification::assertSentOnDemand(CompanyInvitationNotification::class, function ($notification, $channels, $notifiable) {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'nueva@example.com'
            && $mail->viewData['company'] === 'El Clavo'
            && $mail->viewData['role'] === 'Usuario'
            && $mail->viewData['hasAccount'] === false;
    });

    expect(User::where('email', 'nueva@example.com')->exists())->toBeFalse()
        ->and(CompanyInvitation::sole()->status())->toBe('pending')
        ->and($company->users()->count())->toBe(1);

    // Sin aceptar, no hay con qué entrar.
    asGuest();
    $this->post(route('login'), ['email' => 'nueva@example.com', 'password' => INVITEE_PASSWORD]);
    $this->assertGuest();
});

it('al aceptar sin cuenta, la persona elige su nombre y su contraseña, y entra a la compañía con sus permisos', function () {
    ['company' => $company] = invitingOwner();

    $this->post(route('users.store'), [
        'email' => 'nueva@example.com',
        'role_type' => 'user',
        'permissions' => ['accounting.journal_entries' => 'read_write', 'reports.trial_balance' => 'read'],
    ]);
    $token = companyInvitationToken('nueva@example.com');
    asGuest();

    $this->get(route('company-invitation.show', $token))
        ->assertInertia(fn ($page) => $page->component('Auth/AcceptInvitation')
            ->where('state', 'pending')
            ->where('invitation.company', 'El Clavo')
            ->where('invitation.role', 'Usuario')
            ->where('invitation.has_account', false));

    $this->post(route('company-invitation.accept', $token), [
        'name' => 'Nueva Persona',
        'password' => INVITEE_PASSWORD,
        'password_confirmation' => INVITEE_PASSWORD,
    ])->assertRedirect(route('dashboard'))->assertSessionHas('success');

    $user = User::where('email', 'nueva@example.com')->sole();

    expect($user->name)->toBe('Nueva Persona')
        ->and(Hash::check(INVITEE_PASSWORD, $user->password))->toBeTrue()
        ->and($user->password_chosen_at)->not->toBeNull()
        ->and($user->roleTypeFor($company->id))->toBe('user')
        ->and($user->default_company_id)->toBe($company->id)
        ->and($company->users()->wherePivot('status', 'active')->whereKey($user->id)->exists())->toBeTrue()
        ->and(CompanyInvitation::sole()->isAccepted())->toBeTrue();

    $levels = app(ScreenAccessService::class)->levelsFor($user, $company->id);
    expect($levels['accounting.journal_entries'])->toBe('read_write')
        ->and($levels['reports.trial_balance'])->toBe('read')
        ->and($levels['accounting.chart_of_accounts'])->toBe('none');

    // Quedó con la sesión iniciada, en la compañía de la invitación.
    $this->assertAuthenticatedAs($user);
    expect(session('current_company_id'))->toBe($company->id);

    // El enlace ya no sirve.
    $this->get(route('company-invitation.show', $token))->assertInertia(fn ($page) => $page->where('state', 'invalid'));
});

it('a quien ya tiene cuenta solo le suma la compañía: su contraseña y sus otras compañías no cambian', function () {
    $other = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Otra S.A.'],
        ['name' => 'Kevin', 'email' => 'kevin@example.com', 'password' => 'Clave-De-Kevin-1357'],
    );
    ['company' => $company] = invitingOwner();

    $this->post(route('users.store'), [
        'email' => 'Kevin@Example.com',
        'role_type' => 'admin',
        'permissions' => ['inventory.items' => 'read'],
    ])->assertSessionHasNoErrors();
    $token = companyInvitationToken('kevin@example.com');
    asGuest();

    $this->get(route('company-invitation.show', $token))
        ->assertInertia(fn ($page) => $page->where('invitation.has_account', true)->where('invitation.account_name', 'Kevin'));

    // Sin nombre ni contraseña: no se piden.
    $this->post(route('company-invitation.accept', $token))->assertRedirect(route('dashboard'));

    $kevin = $other['user']->fresh();

    expect(Hash::check('Clave-De-Kevin-1357', $kevin->password))->toBeTrue()
        ->and($kevin->name)->toBe('Kevin')
        ->and($kevin->roleTypeFor($company->id))->toBe('admin')
        ->and($kevin->isSuperAdmin($other['company']->id))->toBeTrue()
        ->and($kevin->default_company_id)->toBe($other['company']->id)
        ->and($kevin->companies()->wherePivot('status', 'active')->count())->toBe(2);
});

it('se acepta con la sesión de la cuenta invitada abierta, parada en su propia compañía', function () {
    // El caso real: la dueña de «El Clavo» invita a Kevin, que es dueño de
    // otra licencia y abre el enlace con su sesión, parado en su compañía.
    $kevin = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Kevin Comercial S.A.'],
        ['name' => 'Kevin', 'email' => 'kevin@example.com', 'password' => 'Clave-De-Kevin-1357'],
    );
    ['company' => $company] = invitingOwner();

    $this->post(route('users.store'), [
        'email' => 'kevin@example.com', 'role_type' => 'admin', 'permissions' => ['accounting.journal_entries' => 'read_write'],
    ])->assertSessionHasNoErrors();
    $token = companyInvitationToken('kevin@example.com');

    asGuest();
    $this->actingAs($kevin['user']);
    $this->get(route('dashboard'))->assertOk();
    expect(app(CurrentCompany::class)->id())->toBe($kevin['company']->id);

    $this->post(route('company-invitation.accept', $token))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($kevin['user']->fresh()->roleTypeFor($company->id))->toBe('admin');
});

it('lo que puede dar quien invitó se resuelve contra la compañía de la invitación, no contra la activa', function () {
    ['owner' => $owner, 'company' => $company] = invitingOwner();
    app()->forgetInstance(CurrentCompany::class);

    $grants = app(PermissionGrantService::class);

    expect($grants->grantableRoleTypes($owner, $company->id))->toBe(['admin', 'user'])
        ->and($owner->canGrantPermissionsFor($company->id))->toBeTrue();
});

it('no se acepta con la sesión de otra cuenta abierta', function () {
    invitingOwner();

    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => []]);
    $token = companyInvitationToken('nueva@example.com');

    // Sigue la sesión de la dueña.
    $this->get(route('company-invitation.show', $token))->assertInertia(fn ($page) => $page->where('invitation.session_mismatch', true));

    $this->post(route('company-invitation.accept', $token), [
        'name' => 'X', 'password' => INVITEE_PASSWORD, 'password_confirmation' => INVITEE_PASSWORD,
    ])->assertSessionHasErrors('invitation');

    expect(User::where('email', 'nueva@example.com')->exists())->toBeFalse();
});

it('una invitación vencida no se acepta; reenviarla manda un enlace nuevo y el anterior deja de servir', function () {
    invitingOwner();

    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => []]);
    $first = companyInvitationToken('nueva@example.com');
    $invitation = CompanyInvitation::sole();

    $this->travel(CompanyInvitationService::EXPIRES_IN_DAYS + 1)->days();

    $this->post(route('users.invitations.resend', $invitation->id))->assertSessionHas('success');
    $second = companyInvitationToken('nueva@example.com');
    asGuest();

    expect($second)->not->toBe($first);
    $this->get(route('company-invitation.show', $first))->assertInertia(fn ($page) => $page->where('state', 'invalid'));

    $this->post(route('company-invitation.accept', $second), [
        'name' => 'Nueva', 'password' => INVITEE_PASSWORD, 'password_confirmation' => INVITEE_PASSWORD,
    ])->assertRedirect(route('dashboard'));
});

it('vencida, el enlace lo dice y no deja entrar', function () {
    invitingOwner();

    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => []]);
    $token = companyInvitationToken('nueva@example.com');
    asGuest();

    $this->travel(CompanyInvitationService::EXPIRES_IN_DAYS + 1)->days();

    $this->get(route('company-invitation.show', $token))->assertInertia(fn ($page) => $page->where('state', 'expired'));
    $this->post(route('company-invitation.accept', $token), [
        'name' => 'Nueva', 'password' => INVITEE_PASSWORD, 'password_confirmation' => INVITEE_PASSWORD,
    ])->assertSessionHasErrors('invitation');

    expect(User::where('email', 'nueva@example.com')->exists())->toBeFalse();
});

it('cancelada, el enlace ya no sirve', function () {
    invitingOwner();

    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => []]);
    $token = companyInvitationToken('nueva@example.com');

    $this->delete(route('users.invitations.destroy', CompanyInvitation::sole()->id))->assertSessionHas('success');
    asGuest();

    expect(CompanyInvitation::count())->toBe(0);
    $this->get(route('company-invitation.show', $token))->assertInertia(fn ($page) => $page->where('state', 'invalid'));
});

it('invitar otra vez al mismo correo actualiza la invitación, no la duplica', function () {
    invitingOwner();

    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => []]);
    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'admin', 'permissions' => ['banking.accounts' => 'read']]);

    $invitation = CompanyInvitation::sole();

    expect($invitation->role_type)->toBe('admin')
        ->and($invitation->screen_permissions)->toBe(['banking.accounts' => 'read'])
        ->and($invitation->send_count)->toBe(2);
});

it('no invita a quien ya tiene acceso, ni a uno mismo', function () {
    ['owner' => $owner] = invitingOwner();

    $this->post(route('users.store'), ['email' => $owner->email, 'role_type' => 'user', 'permissions' => []])
        ->assertSessionHasErrors('email');

    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => []]);
    $token = companyInvitationToken('nueva@example.com');
    asGuest();
    $this->post(route('company-invitation.accept', $token), ['name' => 'Nueva', 'password' => INVITEE_PASSWORD, 'password_confirmation' => INVITEE_PASSWORD]);

    $this->actingAs($owner);
    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => []])
        ->assertSessionHasErrors(['email' => 'Esa persona ya tiene acceso a esta compañía.']);
});

it('el cupo de la licencia cuenta las invitaciones sin aceptar', function () {
    invitingOwner(['max_users' => 1]);

    $this->post(route('users.store'), ['email' => 'uno@example.com', 'role_type' => 'user', 'permissions' => []])->assertSessionHasNoErrors();
    $this->post(route('users.store'), ['email' => 'dos@example.com', 'role_type' => 'user', 'permissions' => []])->assertSessionHasErrors('permissions');

    expect(CompanyInvitation::count())->toBe(1);
});

it('si a quien invitó le quitaron el acceso, la invitación ya no se acepta', function () {
    ['owner' => $owner, 'company' => $company] = invitingOwner();
    $grants = app(PermissionGrantService::class);

    $admin = $grants->createUser($owner, $company, ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Secreto#1234'], 'admin', []);
    $grants->writeScreenLevels($company->id, $admin, ['accounting.journal_entries' => 'read_write']);

    $this->actingAs($admin);
    $this->post(route('users.store'), [
        'email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => ['accounting.journal_entries' => 'read_write'],
    ])->assertSessionHasNoErrors();
    $token = companyInvitationToken('nueva@example.com');

    // La dueña le baja el acceso al administrador.
    $grants->writeScreenLevels($company->id, $admin, ['accounting.journal_entries' => 'read']);
    asGuest();

    $this->post(route('company-invitation.accept', $token), [
        'name' => 'Nueva', 'password' => INVITEE_PASSWORD, 'password_confirmation' => INVITEE_PASSWORD,
    ])->assertSessionHasErrors('invitation');

    expect(User::where('email', 'nueva@example.com')->exists())->toBeFalse();
});

it('un administrador gestiona las invitaciones de usuarios, pero no las de administradores', function () {
    ['owner' => $owner, 'company' => $company] = invitingOwner();

    $this->post(route('users.store'), ['email' => 'otro-admin@example.com', 'role_type' => 'admin', 'permissions' => []]);
    $adminInvitation = CompanyInvitation::sole();

    $admin = app(PermissionGrantService::class)->createUser($owner, $company, ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Secreto#1234'], 'admin', []);
    $this->actingAs($admin);

    $this->post(route('users.invitations.resend', $adminInvitation->id))->assertForbidden();
    $this->delete(route('users.invitations.destroy', $adminInvitation->id))->assertForbidden();
    expect(CompanyInvitation::count())->toBe(1);

    $this->post(route('users.store'), ['email' => 'usuario@example.com', 'role_type' => 'user', 'permissions' => []]);
    $userInvitation = CompanyInvitation::where('email', 'usuario@example.com')->sole();

    $this->delete(route('users.invitations.destroy', $userInvitation->id))->assertSessionHas('success');
    expect(CompanyInvitation::count())->toBe(1);
});

it('la lista de usuarios muestra las invitaciones sin aceptar', function () {
    invitingOwner();

    $this->post(route('users.store'), ['email' => 'nueva@example.com', 'role_type' => 'user', 'permissions' => ['banking.accounts' => 'read']]);

    $this->get(route('users.index'))->assertInertia(fn ($page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.email', 'nueva@example.com')
        ->where('invitations.0.status', 'pending')
        ->where('invitations.0.access.0.label', 'Bancos')
        ->where('invitations.0.access.0.screens.0.level', 'read'));
});
