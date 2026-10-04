<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;
use App\Notifications\MembershipStatusNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;

/*
|--------------------------------------------------------------------------
| Cambiar el rol de una persona, y avisarle cuando cambia su acceso
|--------------------------------------------------------------------------
|
| El Superusuario convierte Usuarios en Administradores y al revés
| (PermissionGrantService::changeRole). Suspender, reactivar y desactivar le
| mandan un correo a la persona (UserLifecycleService).
|
*/

beforeEach(fn () => Notification::fake());

/** Una compañía con licencia, su Superusuaria con la sesión iniciada, un Administrador y un Usuario. */
function teamCompany(array $license = []): array
{
    $activation = app(LicenseActivationService::class)->activate(
        License::factory()->create($license)->code,
        ['legal_name' => 'Ferretería El Clavo S.A.', 'trade_name' => 'El Clavo'],
        ['name' => 'Dueña', 'email' => 'duena@example.com', 'password' => 'Secreto#1234'],
    );
    $owner = $activation['user'];
    $company = $activation['company'];
    $grants = app(PermissionGrantService::class);

    $admin = $grants->createUser($owner, $company, ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'], 'admin', []);
    $user = $grants->createUser($owner, $company, ['name' => 'Beto Usuario', 'email' => 'beto@example.com', 'password' => 'Secreto#1234'], 'user', []);
    $grants->writeScreenLevels($company->id, $user, ['accounting.journal_entries' => 'read']);

    test()->actingAs($owner);

    return compact('owner', 'company', 'admin', 'user');
}

it('el Superusuario convierte a un Usuario en Administrador, sin tocarle los permisos', function () {
    ['company' => $company, 'user' => $user] = teamCompany();

    $this->get(route('users.index'))->assertInertia(fn ($page) => $page
        ->where('users', fn ($users) => collect($users)->firstWhere('email', 'beto@example.com')['can_change_role'] === true));

    $this->put(route('users.role', $user->id), ['role_type' => 'admin'])
        ->assertSessionHas('success', 'Beto Usuario ahora es Administrador en esta compañía.');

    expect($user->fresh()->roleTypeFor($company->id))->toBe('admin')
        ->and($user->fresh()->canGrantPermissionsFor($company->id))->toBeTrue()
        ->and(app(ScreenAccessService::class)->levelFor($user, $company->id, 'accounting.journal_entries'))->toBe('read');

    $log = AuditLog::where('action', 'user_role_changed')->sole();
    expect($log->old_values)->toBe(['role_type' => 'user'])
        ->and($log->new_values)->toBe(['role_type' => 'admin']);

    // Y ahora entra a Usuarios.
    $this->actingAs($user->fresh());
    $this->get(route('users.index'))->assertOk();
});

it('y a un Administrador en Usuario, que deja de gestionar personas', function () {
    ['company' => $company, 'admin' => $admin] = teamCompany();

    $this->put(route('users.role', $admin->id), ['role_type' => 'user'])->assertSessionHas('success');

    expect($admin->fresh()->roleTypeFor($company->id))->toBe('user');

    $this->actingAs($admin->fresh());
    $this->get(route('users.index'))->assertForbidden();
});

it('un Administrador no cambia roles', function () {
    ['company' => $company, 'admin' => $admin, 'user' => $user] = teamCompany();

    $this->actingAs($admin);
    $this->get(route('users.index'))->assertInertia(fn ($page) => $page
        ->where('users', fn ($users) => collect($users)->firstWhere('email', 'beto@example.com')['can_change_role'] === false));

    $this->put(route('users.role', $user->id), ['role_type' => 'admin'])->assertSessionHas('error');

    expect($user->fresh()->roleTypeFor($company->id))->toBe('user');
});

it('el rol del Superusuario no se cambia', function () {
    ['owner' => $owner, 'company' => $company] = teamCompany();

    $this->put(route('users.role', $owner->id), ['role_type' => 'user'])->assertSessionHas('error');

    expect($owner->fresh()->isSuperAdmin($company->id))->toBeTrue();
});

it('ascender respeta el cupo de administradores, contando las invitaciones sin aceptar', function () {
    ['company' => $company, 'user' => $user] = teamCompany(['max_admins' => 2]);

    // Ana ya ocupa uno; una invitación pendiente de administrador ocupa el otro.
    $this->post(route('users.store'), ['email' => 'otra@example.com', 'role_type' => 'admin', 'permissions' => []])->assertSessionHasNoErrors();

    $this->put(route('users.role', $user->id), ['role_type' => 'admin'])
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'máximo de 2 administradores'));

    expect($user->fresh()->roleTypeFor($company->id))->toBe('user')
        ->and(CompanyInvitation::count())->toBe(1);
});

it('suspender, reactivar y desactivar le avisan a la persona por correo, con sus compañías', function (string $route, string $status, string $done) {
    ['user' => $user] = teamCompany();

    $this->post(route($route, $user->id))
        ->assertSessionHas('success', "Beto Usuario {$done} y le avisamos por correo a beto@example.com.");

    Notification::assertSentTo($user, MembershipStatusNotification::class, function ($notification) use ($user, $status) {
        $mail = $notification->toMail($user);

        return $mail->viewData['status'] === $status
            && $mail->viewData['companies'] === ['El Clavo']
            && $mail->viewData['changedBy'] === 'Dueña';
    });
})->with([
    'suspender' => ['users.suspend', 'suspended', 'fue suspendido'],
    'reactivar' => ['users.reactivate', 'active', 'fue reactivado'],
    'desactivar' => ['users.deactivate', 'deactivated', 'fue desactivado'],
]);

it('el correo de cada caso dice lo que pasó', function (string $status, string $subject) {
    $user = User::factory()->make(['name' => 'Beto']);
    $mail = (new MembershipStatusNotification($status, ['El Clavo'], 'Dueña'))->toMail($user);

    expect($mail->subject)->toBe($subject)
        ->and((string) $mail->render())->toContain('El Clavo');
})->with([
    ['suspended', 'Tu acceso en CONTAPP quedó suspendido'],
    ['active', 'Ya podés volver a entrar a CONTAPP'],
    ['deactivated', 'Se dio de baja tu acceso en CONTAPP'],
]);

it('si el correo falla, el cambio igual se hace y el mensaje lo dice', function () {
    ['company' => $company, 'user' => $user] = teamCompany();

    Notification::swap(new class extends NotificationFake
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('SMTP caído');
        }
    });

    $this->post(route('users.suspend', $user->id))
        ->assertSessionHas('success', 'Beto Usuario fue suspendido, pero no se pudo enviar el correo a beto@example.com.');

    expect($company->users()->whereKey($user->id)->first()->pivot->status)->toBe('suspended');
});
