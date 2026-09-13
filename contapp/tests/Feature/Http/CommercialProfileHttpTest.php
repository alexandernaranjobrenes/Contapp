<?php

use App\Domains\Licensing\Models\CommercialFollowUp;
use App\Domains\Licensing\Models\CommercialProfile;
use App\Domains\Licensing\Models\License;

it('muestra la ficha comercial de una licencia aunque todavía no tenga perfil', function () {
    loginAsPropietario();
    $license = License::factory()->create();

    $this->get(route('backoffice.licenses.commercial-profile.show', $license->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Backoffice/Licenses/CommercialProfile')
            ->where('profile', null)
        );
});

it('crea el perfil comercial la primera vez que se guarda (upsert)', function () {
    loginAsPropietario();
    $license = License::factory()->create();

    $this->post(route('backoffice.licenses.commercial-profile.store', $license->id), [
        'contact_name' => 'María Pérez',
        'phone' => '8888-0000',
        'commercial_email' => 'maria@cliente.com',
        'referral_source' => 'Referido',
        'notes' => 'Cliente clave',
    ])->assertSessionHasNoErrors();

    $profile = CommercialProfile::where('license_id', $license->id)->sole();
    expect($profile->contact_name)->toBe('María Pérez');

    // Guardar de nuevo actualiza el mismo perfil, no crea uno segundo.
    $this->post(route('backoffice.licenses.commercial-profile.store', $license->id), [
        'contact_name' => 'María Pérez Actualizada',
    ])->assertSessionHasNoErrors();

    expect(CommercialProfile::where('license_id', $license->id)->count())->toBe(1)
        ->and($profile->fresh()->contact_name)->toBe('María Pérez Actualizada');
});

it('registra una interacción comercial, creando el perfil de oficio si no existía', function () {
    $propietario = loginAsPropietario();
    $license = License::factory()->create();

    $this->post(route('backoffice.licenses.commercial-interactions.store', $license->id), [
        'type' => 'call',
        'occurred_at' => '2026-01-15',
        'summary' => 'Llamada de seguimiento inicial.',
    ])->assertSessionHasNoErrors();

    $profile = CommercialProfile::where('license_id', $license->id)->sole();
    $interaction = $profile->interactions()->sole();

    expect($interaction->type)->toBe('call')
        ->and($interaction->author_id)->toBe($propietario->id);
});

it('rechaza un tipo de interacción inválido', function () {
    loginAsPropietario();
    $license = License::factory()->create();

    $this->post(route('backoffice.licenses.commercial-interactions.store', $license->id), [
        'type' => 'fax',
        'occurred_at' => '2026-01-15',
        'summary' => 'x',
    ])->assertSessionHasErrors('type');
});

it('no existe ninguna ruta para editar o borrar una interacción (append-only reforzado en el enrutamiento)', function () {
    expect(fn () => route('backoffice.commercial-interactions.update', 1))->toThrow(\Symfony\Component\Routing\Exception\RouteNotFoundException::class)
        ->and(fn () => route('backoffice.commercial-interactions.destroy', 1))->toThrow(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);
});

it('agenda un seguimiento y lo marca como completado', function () {
    loginAsPropietario();
    $license = License::factory()->create();

    $this->post(route('backoffice.licenses.commercial-follow-ups.store', $license->id), [
        'next_action_date' => '2026-02-01',
        'action_type' => 'Llamar antes de renovar',
    ])->assertSessionHasNoErrors();

    $followUp = CommercialFollowUp::sole();
    expect($followUp->status)->toBe('pending');

    $this->post(route('backoffice.commercial-follow-ups.complete', $followUp->id))->assertSessionHasNoErrors();

    expect($followUp->fresh()->status)->toBe('completed');
});

it('nextPendingFollowUp devuelve el seguimiento pendiente más próximo, ignorando los completados', function () {
    $license = License::factory()->create();
    $profile = CommercialProfile::create(['license_id' => $license->id]);

    $profile->followUps()->create(['next_action_date' => '2026-01-01', 'action_type' => 'Ya resuelto pero el más antiguo', 'status' => 'completed']);
    $profile->followUps()->create(['next_action_date' => '2026-03-01', 'action_type' => 'Lejano', 'status' => 'pending']);
    $soonest = $profile->followUps()->create(['next_action_date' => '2026-01-10', 'action_type' => 'Más próximo', 'status' => 'pending']);

    $profile->load('followUps');

    expect($profile->nextPendingFollowUp()->id)->toBe($soonest->id);
});

it('el listado de licencias expone el seguimiento pendiente más próximo de cada una', function () {
    loginAsPropietario();
    $license = License::factory()->create();

    $this->post(route('backoffice.licenses.commercial-follow-ups.store', $license->id), [
        'next_action_date' => '2026-03-01', 'action_type' => 'Lejano',
    ]);
    $this->post(route('backoffice.licenses.commercial-follow-ups.store', $license->id), [
        'next_action_date' => '2026-01-10', 'action_type' => 'Más próximo',
    ]);

    $this->get(route('backoffice.licenses.index'))
        ->assertInertia(fn ($page) => $page
            ->where('licenses.0.next_pending_follow_up.action_type', 'Más próximo')
        );
});

it('rechaza el acceso a la ficha comercial a un usuario de compañía: no es Propietario', function () {
    logInAsCompanyUser();
    $license = License::factory()->create();

    $this->get(route('backoffice.licenses.commercial-profile.show', $license->id))->assertRedirect(route('backoffice.login'));
});
