<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;
use App\Domains\Licensing\Models\LicenseInvitation;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Domains\Licensing\Services\LicenseInvitationService;
use App\Models\User;
use App\Notifications\LicenseInvitationNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Asignar una licencia desde el backoffice, y aceptarla desde el correo
|--------------------------------------------------------------------------
*/

beforeEach(fn () => Notification::fake());

const NEW_OWNER_PASSWORD = 'Mi-Clave-Propia-2468';

/**
 * Sale del backoffice para seguir como la persona que recibió el correo.
 *
 * En un test todas las requests comparten el proceso, y la de una ruta del
 * backoffice deja «propietario» como guard por defecto (lo hace el
 * middleware auth:propietario). En la aplicación cada request empieza de
 * cero; acá hay que volver a «web» a mano.
 */
function leaveBackoffice(): void
{
    Auth::guard('propietario')->logout();
    Auth::shouldUse('web');
}

/** El enlace que llegó por correo a esa dirección (el último). */
function invitationLink(string $email): string
{
    $link = null;

    Notification::assertSentOnDemand(
        LicenseInvitationNotification::class,
        function (LicenseInvitationNotification $notification, array $channels, object $notifiable) use ($email, &$link) {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }

            $link = $notification->toMail($notifiable)->viewData['url'];

            return true;
        },
    );

    return $link;
}

function invitationToken(string $email): string
{
    return basename(parse_url(invitationLink($email), PHP_URL_PATH));
}

/** Los datos de la compañía que se cargan al aceptar. */
function acceptance(array $overrides = []): array
{
    return array_merge(['legal_name' => 'Empresa de Kevin S.A.', 'trade_name' => 'Kevin', 'tax_id' => '3-101-555555'], $overrides);
}

/** Una cuenta que ya existe, con la contraseña elegida por ella o puesta por otra persona. */
function existingAccount(bool $ownPassword = true, string $email = 'kevin@example.com'): User
{
    return User::factory()->create([
        'name' => 'Kevin',
        'email' => $email,
        'password' => 'Clave-Actual-1357',
        'password_chosen_at' => $ownPassword ? now() : null,
        'status' => 'active',
    ]);
}

function issueLicense(array $assignment): TestResponse
{
    $category = LicenseCategory::factory()->create(['max_companies' => 2]);

    return test()->post(route('backoffice.licenses.store'), [
        'category_id' => $category->id,
        'expires_at' => now()->addYear()->toDateString(),
        ...$assignment,
    ]);
}

// ── Emitir y asignar de una vez ──────────────────────────────────────────

it('emite una licencia asignada a una cuenta existente: queda reservada y le llega el correo', function () {
    $propietario = loginAsPropietario();
    existingAccount();

    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'kevin@example.com'));

    $license = License::sole();
    $invitation = $license->invitation;

    expect($license->superuser_id)->toBeNull()
        ->and($invitation->account_type)->toBe('existing')
        ->and($invitation->status())->toBe('pending')
        ->and($invitation->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(29)->toBeLessThanOrEqual(30)
        ->and($invitation->send_count)->toBe(1)
        ->and($invitation->created_by)->toBe($propietario->id);

    invitationLink('kevin@example.com');

    expect(AuditLog::where('auditable_id', $license->id)->pluck('action')->all())
        ->toContain('license_issued', 'license_invitation_assigned', 'license_invitation_sent');

    // Reservada: su código ya no sirve para activarla por la puerta pública.
    leaveBackoffice();
    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Otra Empresa',
        'name' => 'Otra Persona',
        'email' => 'otra@example.com',
        'password' => 'Secreto#1234',
        'password_confirmation' => 'Secreto#1234',
    ])->assertSessionHasErrors(['code' => 'Esta licencia está asignada a una persona y se activa desde el correo que recibió.']);
});

it('emite una licencia asignada a una cuenta nueva: la cuenta no existe hasta que la acepte', function () {
    loginAsPropietario();

    issueLicense(['assign_to' => 'new', 'assign_email' => 'nueva@example.com', 'assign_name' => 'Ana Nueva'])
        ->assertSessionHasNoErrors();

    expect(License::sole()->invitation->only(['account_type', 'name', 'email']))
        ->toBe(['account_type' => 'new', 'name' => 'Ana Nueva', 'email' => 'nueva@example.com'])
        ->and(User::where('email', 'nueva@example.com')->exists())->toBeFalse();

    invitationLink('nueva@example.com');
});

it('si la asignación no se puede hacer, tampoco se emite la licencia', function (array $assignment, string $field, Closure $setup) {
    loginAsPropietario();
    $setup();
    $licensesBefore = License::count();

    issueLicense($assignment)->assertSessionHasErrors($field);

    expect(License::count())->toBe($licensesBefore);
    Notification::assertNothingSent();
})->with([
    'cuenta existente que no existe' => [['assign_to' => 'existing', 'assign_email' => 'nadie@example.com'], 'assign_email', fn () => null],
    'cuenta nueva con un correo que ya tiene cuenta' => [['assign_to' => 'new', 'assign_email' => 'kevin@example.com', 'assign_name' => 'Kevin'], 'assign_email', fn () => existingAccount()],
    'cuenta nueva sin nombre' => [['assign_to' => 'new', 'assign_email' => 'nueva@example.com'], 'assign_name', fn () => null],
    'sin correo' => [['assign_to' => 'existing'], 'assign_email', fn () => null],
    'una cuenta que ya es dueña de una licencia' => [['assign_to' => 'existing', 'assign_email' => 'duena@example.com'], 'assign_email', fn () => app(LicenseActivationService::class)->activate(
        License::factory()->create()->code, ['legal_name' => 'Ya Tiene'], ['name' => 'Dueña', 'email' => 'duena@example.com', 'password' => 'Secreto#1234'],
    )],
]);

it('un correo no puede tener dos licencias asignadas esperando', function () {
    loginAsPropietario();
    existingAccount();

    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com'])->assertSessionHasNoErrors();
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com'])->assertSessionHasErrors('assign_email');

    expect(License::count())->toBe(1);
});

it('sin asignar, emitir sigue como siempre: el código completo para entregarlo', function () {
    loginAsPropietario();

    issueLicense(['assign_to' => 'none'])->assertSessionHas('success', fn ($message) => str_contains($message, License::sole()->code));

    expect(LicenseInvitation::count())->toBe(0);
    Notification::assertNothingSent();
});

// ── Aceptar ──────────────────────────────────────────────────────────────

it('una cuenta existente con contraseña propia acepta con los datos de su compañía, sin tocar su contraseña', function () {
    loginAsPropietario();
    $kevin = existingAccount(ownPassword: true);
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);
    $token = invitationToken('kevin@example.com');
    leaveBackoffice();

    $this->get(route('license-invitation.show', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/AcceptLicense')
            ->where('state', 'pending')
            ->where('invitation.email', 'kevin@example.com')
            ->where('invitation.requires_password', false));

    $this->post(route('license-invitation.accept', $token), acceptance())
        ->assertRedirect(route('dashboard'));

    $license = License::sole();
    $company = Company::where('legal_name', 'Empresa de Kevin S.A.')->sole();
    $kevin->refresh();

    expect($license->superuser_id)->toBe($kevin->id)
        ->and($company->license_id)->toBe($license->id)
        ->and($kevin->default_company_id)->toBe($company->id)
        ->and(Hash::check('Clave-Actual-1357', $kevin->password))->toBeTrue()
        ->and($license->invitation->status())->toBe('accepted');

    $this->assertAuthenticatedAs($kevin);

    // El enlace ya no sirve.
    $this->get(route('license-invitation.show', $token))->assertInertia(fn ($page) => $page->where('state', 'invalid'));
});

it('una cuenta existente con la contraseña que le puso otra persona tiene que elegir una nueva para aceptar', function () {
    loginAsPropietario();

    // Una cuenta que un Superusuario dio de alta en su compañía: la
    // contraseña la eligió él.
    $owner = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code, ['legal_name' => 'Empresa Ajena'],
        ['name' => 'Dueña', 'email' => 'duena@example.com', 'password' => 'Secreto#1234'],
    );
    $kevin = app(PermissionGrantService::class)->createUser(
        $owner['user'], $owner['company'],
        ['name' => 'Kevin', 'email' => 'kevin@example.com', 'password' => 'Clave-Puesta-1357'], 'user', [],
    );
    expect($kevin->password_chosen_at)->toBeNull();

    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);
    $token = invitationToken('kevin@example.com');
    leaveBackoffice();

    $this->get(route('license-invitation.show', $token))
        ->assertInertia(fn ($page) => $page->where('invitation.requires_password', true));

    // Sin contraseña nueva, o con una que no cumple, no se activa.
    $this->post(route('license-invitation.accept', $token), acceptance())->assertSessionHasErrors('password');
    $this->post(route('license-invitation.accept', $token), acceptance(['password' => 'corta', 'password_confirmation' => 'corta']))->assertSessionHasErrors('password');

    $license = License::whereHas('invitation')->sole();
    expect($license->superuser_id)->toBeNull();

    $this->post(route('license-invitation.accept', $token), acceptance([
        'password' => NEW_OWNER_PASSWORD,
        'password_confirmation' => NEW_OWNER_PASSWORD,
    ]))->assertRedirect(route('dashboard'));

    $kevin->refresh();

    expect($license->fresh()->superuser_id)->toBe($kevin->id)
        ->and(Hash::check(NEW_OWNER_PASSWORD, $kevin->password))->toBeTrue()
        ->and($kevin->password_chosen_at)->not->toBeNull()
        // Sigue siendo Usuario en la compañía de la otra persona.
        ->and($kevin->roleTypeFor($owner['company']->id))->toBe('user');
});

it('una cuenta nueva se crea al aceptar, siempre con la contraseña que elige la persona', function () {
    loginAsPropietario();
    issueLicense(['assign_to' => 'new', 'assign_email' => 'nueva@example.com', 'assign_name' => 'Ana Nueva']);
    $token = invitationToken('nueva@example.com');
    leaveBackoffice();

    $this->get(route('license-invitation.show', $token))
        ->assertInertia(fn ($page) => $page
            ->where('invitation.new_account', true)
            ->where('invitation.requires_password', true)
            ->where('invitation.name', 'Ana Nueva'));

    $this->post(route('license-invitation.accept', $token), acceptance())->assertSessionHasErrors('password');
    expect(User::where('email', 'nueva@example.com')->exists())->toBeFalse();

    $this->post(route('license-invitation.accept', $token), acceptance([
        'password' => NEW_OWNER_PASSWORD,
        'password_confirmation' => NEW_OWNER_PASSWORD,
    ]))->assertRedirect(route('dashboard'));

    $ana = User::where('email', 'nueva@example.com')->sole();

    expect($ana->name)->toBe('Ana Nueva')
        ->and(Hash::check(NEW_OWNER_PASSWORD, $ana->password))->toBeTrue()
        ->and($ana->password_chosen_at)->not->toBeNull()
        ->and(License::sole()->superuser_id)->toBe($ana->id);

    $this->assertAuthenticatedAs($ana);
});

it('quien acepta con otra sesión iniciada en el navegador queda con la cuenta dueña de la licencia', function () {
    loginAsPropietario();
    existingAccount();
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);
    $token = invitationToken('kevin@example.com');
    leaveBackoffice();

    $someoneElse = User::factory()->create(['password' => 'Otra-Clave-1111']);
    $this->post(route('login'), ['email' => $someoneElse->email, 'password' => 'Otra-Clave-1111']);

    $this->post(route('license-invitation.accept', $token), acceptance())->assertRedirect(route('dashboard'));

    Auth::forgetGuards();
    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs(User::where('email', 'kevin@example.com')->sole());
});

// ── Vencimiento, reenvío y cambios ───────────────────────────────────────

it('a los 30 minutos el enlace vence y la licencia no se activa; reenviar da un enlace nuevo', function () {
    loginAsPropietario();
    existingAccount();
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);
    $license = License::sole();
    $oldToken = invitationToken('kevin@example.com');

    $this->travel(31)->minutes();

    $this->get(route('license-invitation.show', $oldToken))->assertInertia(fn ($page) => $page->where('state', 'expired'));
    $this->post(route('license-invitation.accept', $oldToken), acceptance())->assertSessionHasErrors('invitation');
    expect($license->fresh()->superuser_id)->toBeNull();

    // El backoffice reenvía: enlace nuevo, otros 30 minutos; el viejo ya no sirve.
    $this->post(route('backoffice.licenses.invitation.resend', $license->id))->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(LicenseInvitationNotification::class, 2);
    $newToken = invitationToken('kevin@example.com');

    expect($newToken)->not->toBe($oldToken)
        ->and($license->fresh()->invitation->send_count)->toBe(2);

    $this->get(route('license-invitation.show', $oldToken))->assertInertia(fn ($page) => $page->where('state', 'invalid'));

    leaveBackoffice();
    $this->post(route('license-invitation.accept', $newToken), acceptance())->assertRedirect(route('dashboard'));
    expect($license->fresh()->superuser_id)->not->toBeNull();
});

it('mientras no la acepte, el backoffice puede cambiar a quién está asignada: el enlace anterior deja de servir', function () {
    loginAsPropietario();
    existingAccount();
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);
    $license = License::sole();
    $oldToken = invitationToken('kevin@example.com');

    $this->post(route('backoffice.licenses.invitation.store', $license->id), [
        'assign_to' => 'new',
        'assign_email' => 'otra@example.com',
        'assign_name' => 'Otra Persona',
    ])->assertSessionHasNoErrors();

    $invitation = $license->fresh()->invitation;

    expect($invitation->only(['account_type', 'email', 'name']))
        ->toBe(['account_type' => 'new', 'email' => 'otra@example.com', 'name' => 'Otra Persona'])
        ->and(LicenseInvitation::count())->toBe(1);

    invitationLink('otra@example.com');
    $this->get(route('license-invitation.show', $oldToken))->assertInertia(fn ($page) => $page->where('state', 'invalid'));

    $log = AuditLog::where('action', 'license_invitation_updated')->sole();
    expect($log->old_values['email'])->toBe('kevin@example.com')->and($log->new_values['email'])->toBe('otra@example.com');
});

it('una vez aceptada no se puede cambiar, reenviar ni quitar', function () {
    loginAsPropietario();
    existingAccount();
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);
    $license = License::sole();
    $token = invitationToken('kevin@example.com');

    app(LicenseInvitationService::class)->accept(LicenseInvitation::sole(), acceptance(), null);

    $this->post(route('backoffice.licenses.invitation.store', $license->id), ['assign_to' => 'new', 'assign_email' => 'otra@example.com', 'assign_name' => 'Otra'])
        ->assertSessionHasErrors('invitation');
    $this->post(route('backoffice.licenses.invitation.resend', $license->id))->assertSessionHasErrors('invitation');
    $this->delete(route('backoffice.licenses.invitation.destroy', $license->id))->assertSessionHasErrors('invitation');

    expect($license->fresh()->invitation->email)->toBe('kevin@example.com');
});

it('quitar la asignación libera la licencia: vuelve a activarse con su código', function () {
    loginAsPropietario();
    existingAccount();
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);
    $license = License::sole();
    $token = invitationToken('kevin@example.com');

    $this->delete(route('backoffice.licenses.invitation.destroy', $license->id))->assertSessionHasNoErrors();

    expect(LicenseInvitation::count())->toBe(0)
        ->and(AuditLog::where('action', 'license_invitation_cancelled')->exists())->toBeTrue();

    $this->get(route('license-invitation.show', $token))->assertInertia(fn ($page) => $page->where('state', 'invalid'));

    // El código vuelve a servir.
    expect(app(LicenseActivationService::class)->redeemable($license->code)->id)->toBe($license->id);
});

it('asigna una licencia ya emitida desde su ficha', function () {
    loginAsPropietario();
    existingAccount();
    $license = License::factory()->create();

    $this->post(route('backoffice.licenses.invitation.store', $license->id), [
        'assign_to' => 'existing',
        'assign_email' => 'kevin@example.com',
    ])->assertSessionHasNoErrors();

    expect($license->fresh()->invitation->status())->toBe('pending');
    invitationLink('kevin@example.com');
});

it('no asigna una licencia ya activada, vencida o revocada', function (Closure $license) {
    loginAsPropietario();
    existingAccount();

    $this->post(route('backoffice.licenses.invitation.store', $license()->id), [
        'assign_to' => 'existing',
        'assign_email' => 'kevin@example.com',
    ])->assertSessionHasErrors('invitation');

    Notification::assertNothingSent();
})->with([
    'activada' => [fn () => app(LicenseActivationService::class)->activate(License::factory()->create()->code, ['legal_name' => 'X'], ['name' => 'X', 'email' => 'x@example.com', 'password' => 'Secreto#1234'])['license']],
    'vencida' => [fn () => License::factory()->expired()->create()],
    'revocada' => [fn () => License::factory()->revoked()->create()],
]);

it('si la cuenta ya es dueña de otra licencia al momento de aceptar, no se activa', function () {
    loginAsPropietario();
    $kevin = existingAccount();
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);
    $token = invitationToken('kevin@example.com');
    leaveBackoffice();

    // Mientras tanto activó otra por su cuenta.
    app(LicenseActivationService::class)->activateForExistingUser(License::factory()->create()->code, ['legal_name' => 'Por su cuenta'], $kevin);

    $this->post(route('license-invitation.accept', $token), acceptance())->assertSessionHasErrors('invitation');

    expect(License::whereHas('invitation')->sole()->superuser_id)->toBeNull();
});

// ── El backoffice consulta un correo antes de asignar ────────────────────

it('el lookup dice si el correo tiene cuenta, si su contraseña es propia y si ya tiene licencia', function () {
    loginAsPropietario();
    existingAccount(ownPassword: false);

    $this->getJson(route('backoffice.licenses.account-lookup', ['email' => 'kevin@example.com']))
        ->assertOk()
        ->assertJson(['exists' => true, 'name' => 'Kevin', 'active' => true, 'owned_license' => null, 'password_chosen' => false]);

    $this->getJson(route('backoffice.licenses.account-lookup', ['email' => 'nadie@example.com']))
        ->assertJson(['exists' => false]);
});

it('el lookup y la asignación son solo del Propietario', function () {
    logInAsCompanyUser(null, ['is_super_admin' => true]);
    $license = License::factory()->create();

    $this->get(route('backoffice.licenses.account-lookup', ['email' => 'x@example.com']))->assertRedirect(route('backoffice.login'));
    $this->post(route('backoffice.licenses.invitation.store', $license->id), ['assign_to' => 'new', 'assign_email' => 'x@example.com', 'assign_name' => 'X'])
        ->assertRedirect(route('backoffice.login'));

    expect(LicenseInvitation::count())->toBe(0);
});

// ── El correo ────────────────────────────────────────────────────────────

it('el correo dice qué licencia es, qué va a pedir y cuánto dura el enlace', function () {
    loginAsPropietario();
    existingAccount(ownPassword: false);
    issueLicense(['assign_to' => 'existing', 'assign_email' => 'kevin@example.com']);

    Notification::assertSentOnDemand(LicenseInvitationNotification::class, function ($notification, $channels, $notifiable) {
        $mail = $notification->toMail($notifiable);
        $html = (string) $mail->render();

        expect($mail->subject)->toContain('aceptala')
            ->and($html)->toContain('Hola, Kevin')
            ->and($html)->toContain('2 compañías')
            ->and($html)->toContain('30 minutos')
            ->and($html)->toContain('vas a elegir una nueva');

        return true;
    });
});
