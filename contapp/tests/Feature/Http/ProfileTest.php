<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
|--------------------------------------------------------------------------
| «Mi cuenta»: cada persona edita sus propios datos
|--------------------------------------------------------------------------
*/

const CURRENT_PASSWORD = 'Actual-Clave-1234';
const NEW_PASSWORD = 'Nueva-Clave-5678';

/** Una cuenta con contraseña conocida, con la sesión iniciada. */
function accountUser(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'name' => 'Ana Mora',
        'email' => 'ana@example.com',
        'password' => CURRENT_PASSWORD,
    ], $attributes));

    test()->actingAs($user);

    return $user;
}

/** El enlace que llegó al correo nuevo, sacado de la notificación enviada. */
function emailConfirmationLink(string $newEmail): string
{
    $link = null;

    Notification::assertSentOnDemand(
        ConfirmEmailChangeNotification::class,
        function (ConfirmEmailChangeNotification $notification, array $channels, object $notifiable) use ($newEmail, &$link) {
            if ($notifiable->routes['mail'] !== $newEmail) {
                return false;
            }

            $link = $notification->toMail($notifiable)->viewData['url'];

            return true;
        },
    );

    return $link;
}

// ── Acceso ───────────────────────────────────────────────────────────────

it('exige sesión', function () {
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->put(route('profile.update'), ['name' => 'X'])->assertRedirect(route('login'));
    $this->put(route('profile.password'), [])->assertRedirect(route('login'));
    $this->post(route('profile.email.request'), [])->assertRedirect(route('login'));
});

it('no es para el Propietario: su cuenta es de otro plano', function () {
    loginAsPropietario();

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});

it('muestra los datos de la cuenta y en qué compañías entra, con su rol en cada una', function () {
    // Dueño de su licencia…
    $own = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Empresa Propia', 'trade_name' => 'Propia'],
        ['name' => 'Ana Mora', 'email' => 'ana@example.com', 'password' => CURRENT_PASSWORD],
    );
    // …e invitado como Administrador a la de otra persona.
    $other = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Empresa Ajena', 'trade_name' => 'Ajena'],
        ['name' => 'Otra Dueña', 'email' => 'otra@example.com', 'password' => CURRENT_PASSWORD],
    );
    app(PermissionGrantService::class)->inviteUser($other['user'], $other['company'], 'ana@example.com', 'admin', []);

    $this->actingAs($own['user']);

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Profile/Edit')
            ->where('account.name', 'Ana Mora')
            ->where('account.email', 'ana@example.com')
            ->has('passwordRequirements', 6)
            ->has('memberships', 2)
            ->where('memberships.0.name', 'Ajena')
            ->where('memberships.0.role', 'admin')
            ->where('memberships.0.is_current', false)
            ->where('memberships.1.name', 'Propia')
            ->where('memberships.1.role', 'super_admin')
            ->where('memberships.1.is_current', true)
            ->where('memberships.1.is_default', true));
});

it('cualquier rol entra, también quien no pertenece a ninguna compañía', function () {
    accountUser();

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('memberships', 0));
});

// ── Datos personales ─────────────────────────────────────────────────────

it('cambia su nombre y lo deja en la bitácora', function () {
    $user = accountUser();

    $this->put(route('profile.update'), ['name' => '  Ana Mora Jiménez  '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($user->fresh()->name)->toBe('Ana Mora Jiménez');

    $log = AuditLog::where('action', 'user.profile_updated')->sole();
    expect($log->user_id)->toBe($user->id)
        ->and($log->company_id)->toBeNull()
        ->and($log->old_values)->toBe(['name' => 'Ana Mora'])
        ->and($log->new_values)->toBe(['name' => 'Ana Mora Jiménez']);
});

it('guardar sin cambios no llena la bitácora, y el nombre es obligatorio', function () {
    $user = accountUser();

    $this->put(route('profile.update'), ['name' => 'Ana Mora'])->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'user.profile_updated')->exists())->toBeFalse();

    $this->put(route('profile.update'), ['name' => '   '])->assertSessionHasErrors('name');
    expect($user->fresh()->name)->toBe('Ana Mora');
});

it('guardar los datos solo toca el nombre de la propia cuenta, mande lo que mande el formulario', function () {
    $user = accountUser();
    $other = User::factory()->create(['name' => 'Otra Persona']);

    $this->put(route('profile.update'), [
        'name' => 'Ana Nueva',
        'id' => $other->id,
        'user_id' => $other->id,
        'email' => 'robado@example.com',
        'password' => NEW_PASSWORD,
        'is_super_admin' => true,
        'status' => 'inactive',
    ])->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Ana Nueva')
        ->and($user->email)->toBe('ana@example.com')
        ->and(Hash::check(CURRENT_PASSWORD, $user->password))->toBeTrue()
        ->and((bool) $user->is_super_admin)->toBeFalse()
        ->and($user->status)->toBe('active')
        ->and($other->fresh()->name)->toBe('Otra Persona');
});

// ── Contraseña ───────────────────────────────────────────────────────────

it('cambia su contraseña con la actual, y esta sesión sigue abierta', function () {
    // Entra por el login, no con actingAs(): lo que se comprueba es la
    // sesión de verdad, que es donde el middleware auth.session guarda el
    // hash de la contraseña.
    $user = User::factory()->create(['password' => CURRENT_PASSWORD]);
    $this->post(route('login'), ['email' => $user->email, 'password' => CURRENT_PASSWORD]);
    $this->get(route('profile.edit'))->assertOk();

    $this->put(route('profile.password'), [
        'current_password' => CURRENT_PASSWORD,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::where('action', 'user.password_changed')->sole()->user_id)->toBe($user->id);

    // La sesión que hizo el cambio no se cierra…
    Auth::forgetGuards();
    $this->get(route('profile.edit'))->assertOk();
    $this->assertAuthenticatedAs($user);

    // …y la contraseña vieja ya no entra.
    $this->delete(route('logout'));
    $this->post(route('login'), ['email' => $user->email, 'password' => CURRENT_PASSWORD])->assertSessionHasErrors('email');
    $this->post(route('login'), ['email' => $user->email, 'password' => NEW_PASSWORD])->assertRedirect(route('dashboard'));
});

it('cierra las sesiones que estaban abiertas en otros dispositivos', function () {
    $user = User::factory()->create(['password' => CURRENT_PASSWORD]);

    // Una sesión abierta con la contraseña anterior.
    $this->post(route('login'), ['email' => $user->email, 'password' => CURRENT_PASSWORD]);
    $this->get(route('dashboard'))->assertOk();

    // La contraseña se cambia desde otro dispositivo (otra sesión, que acá
    // se simula escribiendo el resultado: lo que importa es el hash nuevo).
    User::find($user->id)->forceFill(['password' => NEW_PASSWORD])->save();
    Auth::forgetGuards();

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('no cambia la contraseña sin la actual, si es débil, si es la misma o si no coincide', function (array $payload, string $field) {
    $user = accountUser();

    $this->put(route('profile.password'), $payload)->assertSessionHasErrors($field);

    expect(Hash::check(CURRENT_PASSWORD, $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::where('action', 'user.password_changed')->exists())->toBeFalse();
})->with([
    'actual equivocada' => [['current_password' => 'no-es-esta', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD], 'current_password'],
    'sin la actual' => [['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD], 'current_password'],
    'nueva débil' => [['current_password' => CURRENT_PASSWORD, 'password' => 'secreto123', 'password_confirmation' => 'secreto123'], 'password'],
    'nueva igual a la actual' => [['current_password' => CURRENT_PASSWORD, 'password' => CURRENT_PASSWORD, 'password_confirmation' => CURRENT_PASSWORD], 'password'],
    'no coincide' => [['current_password' => CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => 'Otra-Clave-9012'], 'password'],
]);

it('cambiar la contraseña sabiendo la actual no cuenta como haberla elegido por correo', function () {
    // Quien creó la cuenta también conoce la contraseña actual: cambiarla
    // desde acá no prueba que el correo sea de quien la cambia.
    $createdByOther = accountUser(['password_chosen_at' => null]);

    $this->put(route('profile.password'), [
        'current_password' => CURRENT_PASSWORD,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertSessionHasNoErrors();

    expect($createdByOther->fresh()->password_chosen_at)->toBeNull();
});

it('frena la prueba repetida de la contraseña actual, sin gastar intentos por errores en la nueva', function () {
    accountUser();

    // Errores en la contraseña nueva, con la actual bien: no cuentan.
    foreach (range(1, 6) as $attempt) {
        $this->put(route('profile.password'), [
            'current_password' => CURRENT_PASSWORD,
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertSessionHasErrors('password')->assertSessionDoesntHaveErrors('current_password');
    }

    foreach (range(1, 5) as $attempt) {
        $this->put(route('profile.password'), [
            'current_password' => "adivinanza-{$attempt}",
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])->assertSessionHasErrors(['current_password' => 'La contraseña es incorrecta.']);
    }

    // Al sexto ni la prueba, aunque sea la correcta. Vale también para el correo.
    $this->put(route('profile.password'), [
        'current_password' => CURRENT_PASSWORD,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertSessionHasErrors('current_password');

    $this->post(route('profile.email.request'), [
        'email' => 'nuevo@example.com',
        'current_password' => CURRENT_PASSWORD,
    ])->assertSessionHasErrors('current_password');
});

// ── Correo ───────────────────────────────────────────────────────────────

it('pedir el cambio de correo manda un enlace a la casilla nueva y no cambia nada todavía', function () {
    Notification::fake();
    $user = accountUser();

    $this->post(route('profile.email.request'), [
        'email' => 'nuevo@example.com',
        'current_password' => CURRENT_PASSWORD,
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    expect(emailConfirmationLink('nuevo@example.com'))->toContain('/my-account/email/confirm/'.$user->id)
        ->and($user->fresh()->email)->toBe('ana@example.com');

    // Al correo actual no se le manda nada.
    Notification::assertNotSentTo($user, ConfirmEmailChangeNotification::class);

    $log = AuditLog::where('action', 'user.email_change_requested')->sole();
    expect($log->new_values)->toBe(['email' => 'nuevo@example.com']);
});

it('no pide el cambio sin la contraseña actual, con el mismo correo o con uno que ya usa otra cuenta', function (array $payload, string $field) {
    Notification::fake();
    $user = accountUser();
    User::factory()->create(['email' => 'ocupado@example.com']);

    $this->post(route('profile.email.request'), $payload)->assertSessionHasErrors($field);

    Notification::assertNothingSent();
    expect($user->fresh()->email)->toBe('ana@example.com');
})->with([
    'contraseña equivocada' => [['email' => 'nuevo@example.com', 'current_password' => 'no-es-esta'], 'current_password'],
    'sin contraseña' => [['email' => 'nuevo@example.com'], 'current_password'],
    'el mismo correo' => [['email' => 'ana@example.com', 'current_password' => CURRENT_PASSWORD], 'email'],
    'correo de otra cuenta' => [['email' => 'ocupado@example.com', 'current_password' => CURRENT_PASSWORD], 'email'],
    'no es un correo' => [['email' => 'no-es-un-correo', 'current_password' => CURRENT_PASSWORD], 'email'],
]);

it('abrir el enlace cambia el correo, y desde ahí se entra con el nuevo', function () {
    Notification::fake();
    $user = accountUser();
    Password::broker('users')->createToken($user);

    $this->post(route('profile.email.request'), ['email' => 'nuevo@example.com', 'current_password' => CURRENT_PASSWORD]);
    $link = emailConfirmationLink('nuevo@example.com');

    // Con la misma sesión: vuelve a Mi cuenta.
    $this->get($link)->assertRedirect(route('profile.edit'))->assertSessionHas('success');

    $user->refresh();

    expect($user->email)->toBe('nuevo@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        // El enlace de recuperación pedido para el correo anterior ya no sirve.
        ->and(DB::table('password_reset_tokens')->where('email', 'ana@example.com')->exists())->toBeFalse();

    $log = AuditLog::where('action', 'user.email_changed')->sole();
    expect($log->old_values)->toBe(['email' => 'ana@example.com'])
        ->and($log->new_values)->toBe(['email' => 'nuevo@example.com']);

    $this->delete(route('logout'));
    $this->post(route('login'), ['email' => 'ana@example.com', 'password' => CURRENT_PASSWORD])->assertSessionHasErrors('email');
    $this->post(route('login'), ['email' => 'nuevo@example.com', 'password' => CURRENT_PASSWORD])->assertRedirect(route('dashboard'));
});

it('el enlace se puede abrir sin sesión, en otro dispositivo', function () {
    Notification::fake();
    $user = accountUser();

    $this->post(route('profile.email.request'), ['email' => 'nuevo@example.com', 'current_password' => CURRENT_PASSWORD]);
    $link = emailConfirmationLink('nuevo@example.com');

    $this->delete(route('logout'));

    $this->get($link)->assertRedirect(route('login'))->assertSessionHas('success');
    expect($user->fresh()->email)->toBe('nuevo@example.com');
});

it('un enlace alterado, vencido o ya usado no cambia el correo', function (string $case) {
    Notification::fake();
    $user = accountUser();

    $this->post(route('profile.email.request'), ['email' => 'nuevo@example.com', 'current_password' => CURRENT_PASSWORD]);
    $link = emailConfirmationLink('nuevo@example.com');

    $expected = 'ana@example.com';

    if ($case === 'otro correo en el enlace') {
        $link = str_replace('nuevo%40example.com', 'intruso%40example.com', $link);
    } elseif ($case === 'otra cuenta en el enlace') {
        $victim = User::factory()->create(['email' => 'victima@example.com']);
        $link = str_replace('/confirm/'.$user->id, '/confirm/'.$victim->id, $link);
    } elseif ($case === 'vencido') {
        $this->travel(61)->minutes();
    } elseif ($case === 'ya usado') {
        $this->get($link)->assertSessionHas('success');
        // La cuenta vuelve a otro correo: el enlace viejo no la devuelve a «nuevo».
        $user->forceFill(['email' => 'tercero@example.com'])->save();
        $expected = 'tercero@example.com';
    }

    $this->get($link)->assertSessionHas('error')->assertSessionMissing('success');

    expect($user->fresh()->email)->toBe($expected)
        ->and(User::where('email', 'intruso@example.com')->exists())->toBeFalse()
        ->and(User::where('email', 'victima@example.com')->exists())->toBe($case === 'otra cuenta en el enlace');
})->with(['otro correo en el enlace', 'otra cuenta en el enlace', 'vencido', 'ya usado']);

it('si otra cuenta tomó ese correo antes de confirmar, el cambio no se aplica', function () {
    Notification::fake();
    $user = accountUser();

    $this->post(route('profile.email.request'), ['email' => 'nuevo@example.com', 'current_password' => CURRENT_PASSWORD]);
    $link = emailConfirmationLink('nuevo@example.com');

    User::factory()->create(['email' => 'nuevo@example.com']);

    $this->get($link)->assertSessionHas('error');
    expect($user->fresh()->email)->toBe('ana@example.com');
});

it('el correo de confirmación va en español, con el enlace y su vencimiento', function () {
    $mail = (new ConfirmEmailChangeNotification('https://contapp.test/enlace-firmado', 'Ana Mora', 60))
        ->toMail(new stdClass);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Confirmá tu correo nuevo de '.config('app.name'))
        ->and($html)->toContain('Hola, Ana Mora')
        ->and($html)->toContain('https://contapp.test/enlace-firmado')
        ->and($html)->toContain('60 minutos');
});

// ── No depende de la compañía en la que se esté ──────────────────────────

it('una compañía con la licencia vencida no impide editar la propia cuenta', function () {
    $activation = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code,
        ['legal_name' => 'Empresa Vencida'],
        ['name' => 'Ana Mora', 'email' => 'ana@example.com', 'password' => CURRENT_PASSWORD],
    );
    $activation['license']->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    $this->actingAs($activation['user']);

    // En modo de gracia no se puede escribir en la compañía…
    $this->post(route('chart-of-accounts.store'), [])->assertForbidden();

    // …pero la cuenta es de la persona.
    $this->put(route('profile.update'), ['name' => 'Ana Renovada'])->assertSessionHasNoErrors();
    $this->put(route('profile.password'), [
        'current_password' => CURRENT_PASSWORD,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertSessionHasNoErrors();

    expect($activation['user']->fresh()->name)->toBe('Ana Renovada');
});
