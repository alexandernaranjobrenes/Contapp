<?php

use App\Domains\Licensing\Models\Propietario;

it('un propietario inicia sesión con sus credenciales', function () {
    $propietario = Propietario::factory()->create(['password' => bcrypt('secreto123')]);

    $this->post(route('backoffice.login'), [
        'email' => $propietario->email,
        'password' => 'secreto123',
    ])->assertRedirect(route('backoffice.licenses.index'));

    $this->assertAuthenticatedAs($propietario, 'propietario');
});

it('rechaza credenciales inválidas en el login del backoffice', function () {
    $propietario = Propietario::factory()->create();

    $this->post(route('backoffice.login'), [
        'email' => $propietario->email,
        'password' => 'lo-que-sea-incorrecto',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('propietario');
});

it('un propietario puede cerrar sesión', function () {
    $propietario = loginAsPropietario();

    $this->delete(route('backoffice.logout'))->assertRedirect(route('backoffice.login'));

    $this->assertGuest('propietario');
});

it('un usuario de compañía no puede entrar al backoffice del Propietario: guards completamente separados', function () {
    logInAsCompanyUser();

    $this->get(route('backoffice.licenses.index'))->assertRedirect(route('backoffice.login'));

    $this->assertGuest('propietario');
});

it('un propietario no puede entrar a las pantallas de compañía: guards completamente separados', function () {
    loginAsPropietario();

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest('web');
});

it('loguearse en un guard no autentica al otro', function () {
    loginAsPropietario();
    $this->assertAuthenticated('propietario');
    $this->assertGuest('web');

    logInAsCompanyUser();
    $this->assertAuthenticated('web');
});
