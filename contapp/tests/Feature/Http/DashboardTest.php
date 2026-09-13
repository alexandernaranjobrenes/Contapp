<?php

use App\Models\User;

it('muestra el panel con estadísticas cuando el usuario tiene compañía activa', function () {
    logInAsCompanyUser();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('noCompany', false));
});

it('indica que no hay compañía activa cuando el usuario no pertenece a ninguna', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('noCompany', true));
});

it('redirige a login a un invitado que intenta ver el panel', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
