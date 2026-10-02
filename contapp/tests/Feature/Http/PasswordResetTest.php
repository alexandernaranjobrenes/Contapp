<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Licensing\Models\Propietario;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
|--------------------------------------------------------------------------
| «Olvidé mi contraseña»: pedir el enlace
|--------------------------------------------------------------------------
*/

it('muestra la pantalla para pedir el enlace a un invitado', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ForgotPassword')
            ->where('expiresInMinutes', 60)
            ->where('resendAfterSeconds', 60));
});

it('manda al panel a quien ya tiene la sesión iniciada', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('password.request'))->assertRedirect(route('dashboard'));
});

it('envía el enlace por correo a un usuario registrado y lo deja en la bitácora', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPasswordNotification::class);

    $log = AuditLog::where('action', 'user.password_reset_requested')->sole();
    expect($log->user_id)->toBe($user->id)
        ->and($log->company_id)->toBeNull()
        ->and($log->ip_address)->not->toBeNull();
});

it('responde igual con un correo que no existe, y no envía nada', function () {
    Notification::fake();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nadie@ejemplo.test'])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
    expect(AuditLog::where('action', 'user.password_reset_requested')->exists())->toBeFalse();
});

it('no manda un segundo enlace si se pidió otro hace menos de un minuto, y responde igual', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);

    $this->travel(61)->seconds();
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 2);
});

it('frena los pedidos repetidos desde una misma dirección', function () {
    Notification::fake();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.email'), ['email' => "alguien{$attempt}@ejemplo.test"])
            ->assertSessionHasNoErrors();
    }

    $this->post(route('password.email'), ['email' => 'alguien6@ejemplo.test'])
        ->assertSessionHasErrors('email');
});

it('exige un correo con forma de correo', function () {
    $this->post(route('password.email'), ['email' => 'no-es-un-correo'])
        ->assertSessionHasErrors('email');
});

it('no recupera la contraseña del Propietario por la puerta de los clientes', function () {
    Notification::fake();
    $propietario = Propietario::factory()->create();

    $this->post(route('password.email'), ['email' => $propietario->email])
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

it('el correo va en español, con el enlace y su vencimiento', function () {
    $user = User::factory()->create(['name' => 'Ana Mora']);
    $token = Password::broker('users')->createToken($user);

    $mail = (new ResetPasswordNotification($token))->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Restablecé tu contraseña de '.config('app.name'))
        ->and($html)->toContain('Hola, Ana Mora')
        ->and($html)->toContain(route('password.reset', ['token' => $token]))
        ->and($html)->toContain('email='.urlencode($user->email))
        ->and($html)->toContain('60 minutos')
        ->and($html)->toContain('Todos los derechos reservados.')
        ->and($html)->not->toContain('All rights reserved');
});

/*
|--------------------------------------------------------------------------
| El enlace: elegir la contraseña nueva
|--------------------------------------------------------------------------
*/

it('abre el formulario con un enlace vigente', function () {
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ResetPassword')
            ->where('valid', true)
            ->where('email', $user->email)
            ->where('token', $token));
});

it('avisa al abrirlo que un enlace no sirve', function (Closure $link) {
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);

    $this->get($link($user, $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword')->where('valid', false));
})->with([
    'token inventado' => [fn (User $user, string $token) => route('password.reset', ['token' => 'inventado', 'email' => $user->email])],
    'correo de otra persona' => [fn (User $user, string $token) => route('password.reset', ['token' => $token, 'email' => 'otra@ejemplo.test'])],
    'sin correo' => [fn (User $user, string $token) => route('password.reset', ['token' => $token])],
]);

it('avisa al abrirlo que el enlace venció', function () {
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);

    $this->travel(61)->minutes();

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertInertia(fn ($page) => $page->where('valid', false));
});

it('cambia la contraseña con un enlace vigente', function () {
    $user = User::factory()->create(['password' => 'anterior123', 'remember_token' => 'recuerdo-viejo']);
    $token = Password::broker('users')->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Nueva-Clave-4567',
        'password_confirmation' => 'Nueva-Clave-4567',
    ])
        ->assertRedirect(route('login'))
        ->assertSessionHas('success');

    $user->refresh();

    expect(Hash::check('Nueva-Clave-4567', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe('recuerdo-viejo')
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();

    // No inicia la sesión por su cuenta: se entra por el login.
    $this->assertGuest();

    $log = AuditLog::where('action', 'user.password_reset')->sole();
    expect($log->user_id)->toBe($user->id)->and($log->auditable_id)->toBe($user->id);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'Nueva-Clave-4567'])
        ->assertRedirect(route('dashboard'));
});

it('un enlace sirve una sola vez', function () {
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);
    $payload = fn (string $password) => [
        'token' => $token,
        'email' => $user->email,
        'password' => $password,
        'password_confirmation' => $password,
    ];

    $this->post(route('password.update'), $payload('Primera-Clave-1234'))->assertRedirect(route('login'));
    $this->post(route('password.update'), $payload('Segunda-Clave-5678'))->assertSessionHasErrors('token');

    expect(Hash::check('Primera-Clave-1234', $user->refresh()->password))->toBeTrue();
});

it('pedir otro enlace deja sin efecto el anterior', function () {
    $user = User::factory()->create(['password' => 'anterior123']);
    $first = Password::broker('users')->createToken($user);
    Password::broker('users')->createToken($user);

    $this->post(route('password.update'), [
        'token' => $first,
        'email' => $user->email,
        'password' => 'Nueva-Clave-4567',
        'password_confirmation' => 'Nueva-Clave-4567',
    ])->assertSessionHasErrors('token');

    expect(Hash::check('anterior123', $user->refresh()->password))->toBeTrue();
});

it('rechaza un enlace inválido o vencido sin tocar la contraseña', function (string $case) {
    $user = User::factory()->create(['password' => 'anterior123']);
    $token = Password::broker('users')->createToken($user);

    if ($case === 'vencido') {
        $this->travel(61)->minutes();
    }

    $this->post(route('password.update'), [
        'token' => $case === 'inventado' ? 'inventado' : $token,
        'email' => $case === 'correo desconocido' ? 'nadie@ejemplo.test' : $user->email,
        'password' => 'Nueva-Clave-4567',
        'password_confirmation' => 'Nueva-Clave-4567',
    ])
        // El mismo error en los tres casos: no dice si el correo existe.
        ->assertSessionHasErrors(['token' => __('passwords.token')]);

    expect(Hash::check('anterior123', $user->refresh()->password))->toBeTrue()
        ->and(AuditLog::where('action', 'user.password_reset')->exists())->toBeFalse();
})->with(['inventado', 'vencido', 'correo desconocido']);

it('exige una contraseña que cumpla la política, escrita dos veces igual', function (string $password, string $confirmation) {
    $user = User::factory()->create(['password' => 'anterior123']);
    $token = Password::broker('users')->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => $password,
        'password_confirmation' => $confirmation,
    ])->assertSessionHasErrors('password');

    // El enlace sigue sirviendo para corregir y volver a enviar.
    expect(Hash::check('anterior123', $user->refresh()->password))->toBeTrue()
        ->and(Password::broker('users')->tokenExists($user, $token))->toBeTrue();
})->with([
    'muy corta' => ['corta', 'corta'],
    'la de antes: 8 caracteres y nada más' => ['secreto123', 'secreto123'],
    'solo tres números' => ['Nueva-Clave-456', 'Nueva-Clave-456'],
    'sin carácter especial' => ['NuevaClave4567', 'NuevaClave4567'],
    'no coinciden' => ['Nueva-Clave-4567', 'Otra-Distinta-7890'],
]);

/*
|--------------------------------------------------------------------------
| Las sesiones abiertas con la contraseña anterior
|--------------------------------------------------------------------------
*/

it('cierra la sesión que estaba abierta con la contraseña anterior', function () {
    $user = User::factory()->create(['password' => 'anterior123']);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'anterior123']);
    $this->get(route('dashboard'))->assertOk();

    // La contraseña cambia desde otro lado (el enlace del correo, en otro
    // dispositivo). El guard se olvida para que la siguiente request vuelva
    // a leer al usuario, como lo haría un proceso nuevo.
    User::find($user->id)->forceFill(['password' => 'Nueva-Clave-4567'])->save();
    Auth::forgetGuards();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('no cierra la sesión mientras la contraseña sea la misma', function () {
    $user = User::factory()->create(['password' => 'anterior123']);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'anterior123']);
    $this->get(route('dashboard'))->assertOk();
    Auth::forgetGuards();

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);
});
