<?php

use App\Domains\Core\Support\PasswordPolicy;
use App\Domains\Licensing\Models\License;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| La política: 8 caracteres, 4 letras, 4 números, mayúscula, minúscula y
| un carácter especial
|--------------------------------------------------------------------------
*/

it('acepta una contraseña que cumple todo', function (string $password) {
    expect(PasswordPolicy::missing($password))->toBe([]);
})->with([
    'la más corta posible: nueve caracteres' => ['Abcd1234#'],
    'con letras acentuadas y eñe' => ['Ñandú-2026'],
    'larga' => ['Mi-Contraseña-De-Planilla-2026'],
    'con varios símbolos' => ['aB3$dE5&7g9!'],
]);

it('dice qué le falta a una contraseña', function (string $password, array $missing) {
    expect(PasswordPolicy::missing($password))->toBe($missing);
})->with([
    'vacía' => ['', ['8 caracteres', '4 letras', '4 números', 'una mayúscula', 'una minúscula', 'un carácter especial']],
    'muy corta' => ['Ab1#', ['8 caracteres', '4 letras', '4 números']],
    'tres letras' => ['Abc12345#', ['4 letras']],
    'tres números' => ['Abcdef123#', ['4 números']],
    'sin mayúscula' => ['abcd1234#', ['una mayúscula']],
    'sin minúscula' => ['ABCD1234#', ['una minúscula']],
    'sin carácter especial' => ['Abcd12345', ['un carácter especial']],
    // Cuatro letras, cuatro números y nada más: ocho caracteres no alcanzan.
    'ocho justos' => ['Abcd1234', ['un carácter especial']],
    'un espacio no cuenta como carácter especial' => ['Abcd 1234', ['un carácter especial']],
    'solo números' => ['12345678', ['4 letras', 'una mayúscula', 'una minúscula', 'un carácter especial']],
]);

it('manda a la pantalla los mismos requisitos que valida', function () {
    $requirements = PasswordPolicy::requirements();

    expect(array_column($requirements, 'key'))
        ->toBe(['length', 'letters', 'digits', 'uppercase', 'lowercase', 'special']);

    // Cada patrón es una clase de un solo carácter que JavaScript también
    // entiende con la bandera «u»: sin delimitadores ni cuantificadores.
    foreach ($requirements as $requirement) {
        expect($requirement['pattern'])->not->toContain('/')
            ->and(preg_match('/'.$requirement['pattern'].'/u', 'a1A#'))->not->toBeFalse()
            ->and($requirement['min'])->toBeGreaterThan(0);
    }
});

/*
|--------------------------------------------------------------------------
| Se exige en cada pantalla donde se elige una contraseña
|--------------------------------------------------------------------------
*/

it('la activación de licencia muestra los requisitos y rechaza una contraseña débil', function () {
    $this->get(route('license-activation.create'))
        ->assertInertia(fn ($page) => $page->component('Auth/Activate')->has('passwordRequirements', 6));

    $license = License::factory()->create(['max_companies' => 1]);

    $this->post(route('license-activation.store'), [
        'code' => $license->code,
        'legal_name' => 'Mi Empresa S.A.',
        'name' => 'Juan Pérez',
        'email' => 'juan@example.com',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertSessionHasErrors([
        'password' => 'A la contraseña le falta tener al menos: 4 números, una mayúscula, un carácter especial.',
    ]);

    expect(User::where('email', 'juan@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('aceptar una invitación sin cuenta muestra los requisitos y rechaza una contraseña débil', function () {
    // Quien invita ya no pone la contraseña de nadie: la elige la persona al
    // aceptar (CompanyInvitationService).
    ['user' => $owner, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    \Illuminate\Support\Facades\Notification::fake();

    ['invitation' => $invitation] = app(\App\Domains\Core\Services\CompanyInvitationService::class)
        ->invite($owner, $company, 'ana@example.com', 'user', []);
    $token = \Illuminate\Support\Str::random(64);
    $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();

    \Illuminate\Support\Facades\Auth::guard('web')->logout();

    $this->get(route('company-invitation.show', $token))
        ->assertInertia(fn ($page) => $page->component('Auth/AcceptInvitation')->has('passwordRequirements', 6));

    $this->post(route('company-invitation.accept', $token), [
        'name' => 'Ana Admin',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertSessionHasErrors('password');

    expect(User::where('email', 'ana@example.com')->exists())->toBeFalse();
});

it('entrar no exige la política: una contraseña anterior sigue sirviendo', function () {
    $user = User::factory()->create(['password' => 'secreto123']);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'secreto123'])
        ->assertRedirect(route('dashboard'));
});
