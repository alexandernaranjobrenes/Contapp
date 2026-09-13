<?php

use App\Models\User;

it('muestra la página de login a un invitado', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

it('permite iniciar sesión con credenciales correctas', function () {
    $user = User::factory()->create(['password' => bcrypt('secreto123')]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'secreto123',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rechaza credenciales incorrectas', function () {
    $user = User::factory()->create(['password' => bcrypt('secreto123')]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'incorrecta',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('permite cerrar sesión', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
