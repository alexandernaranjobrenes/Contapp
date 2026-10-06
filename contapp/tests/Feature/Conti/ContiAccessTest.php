<?php

use App\Domains\Conti\Models\ContiUsage;
use App\Domains\Conti\Models\ContiUserSetting;
use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El Superusuario reparte el cupo de Conti de su licencia
|--------------------------------------------------------------------------
|
| El cupo es de la licencia y lo comparten todas sus personas, el
| Superusuario incluido. Al invitar o al editar los permisos de alguien, el
| Superusuario —y nadie más— decide si puede usar Conti, sus límites (sin
| pasar los de la licencia) y sus modelos (ContiAccessService).
|
*/

beforeEach(fn () => Notification::fake());

/** Una licencia con Conti, su compañía y su Superusuario, con la sesión iniciada. */
function contiOwner(array $ai = []): array
{
    configureOpenAi();

    $activation = app(LicenseActivationService::class)->activate(
        License::factory()->create(['ai_enabled' => true, 'ai_daily_credits' => 100, 'ai_weekly_credits' => 400, ...$ai])->code,
        ['legal_name' => 'Ferretería El Clavo S.A.', 'trade_name' => 'El Clavo'],
        ['name' => 'Dueña', 'email' => 'duena@example.com', 'password' => 'Secreto#1234'],
    );

    test()->actingAs($activation['user']);

    return ['owner' => $activation['user'], 'user' => $activation['user'], 'company' => $activation['company'], 'license' => $activation['license']];
}

/** Una persona de la compañía con ese rol. */
function contiMember(array $f, string $role = 'user'): User
{
    $grants = app(PermissionGrantService::class);
    $user = User::factory()->create(['default_company_id' => $f['company']->id, 'status' => 'active']);
    $f['company']->users()->attach($user->id, ['is_default' => true, 'status' => 'active']);
    $user->userRoles()->create(['role_id' => $grants->globalRole($role)->id, 'company_id' => $f['company']->id]);
    $grants->writeScreenLevels($f['company']->id, $user, ['accounting.journal_entries' => 'read']);

    return $user;
}

/** Lo que manda el formulario de permisos, con la sección de Conti. */
function contiPermissionsUpdate(array $conti): array
{
    return ['permissions' => ['accounting.journal_entries' => 'read'], 'conti' => $conti];
}

function contiGuest(): void
{
    Auth::guard('web')->logout();
    session()->flush();
    app()->forgetInstance(CurrentCompany::class);
}

it('solo el Superusuario ve la sección de Conti al invitar y al editar permisos, y solo si la licencia tiene Conti', function () {
    $f = contiOwner(['ai_user_daily_credits' => 50]);
    $admin = contiMember($f, 'admin');
    $user = contiMember($f);

    $this->get(route('users.create'))->assertInertia(fn ($page) => $page
        ->where('conti.max_daily', '50.00')
        ->where('conti.max_weekly', '400.00')
        ->where('conti.current.enabled', true)
        ->has('conti.models', count(config('conti.models'))));

    $this->get(route('users.permissions.edit', $admin->id))->assertInertia(fn ($page) => $page
        ->where('conti.current.enabled', true)
        ->where('conti.usage.today', '0.00'));

    $this->actingAs($admin);
    $this->get(route('users.create'))->assertInertia(fn ($page) => $page->where('conti', null));
    $this->get(route('users.permissions.edit', $user->id))->assertInertia(fn ($page) => $page->where('conti', null));

    $f['license']->update(['ai_enabled' => false]);
    $this->actingAs($f['owner'])->get(route('users.create'))->assertInertia(fn ($page) => $page->where('conti', null));
});

it('al invitar, el Superusuario elige lo de Conti, y se aplica cuando la persona acepta', function () {
    $f = contiOwner();

    $this->post(route('users.store'), [
        'email' => 'nueva@example.com',
        'role_type' => 'user',
        'permissions' => ['accounting.journal_entries' => 'read'],
        'conti' => ['enabled' => true, 'daily_credits' => '10', 'weekly_credits' => '30', 'models' => ['gpt-4.1-mini', 'gpt-4.1-nano']],
    ])->assertRedirect(route('users.index'));

    expect(CompanyInvitation::sole()->conti_settings)->toBe([
        'enabled' => true, 'daily_credits' => '10.00', 'weekly_credits' => '30.00', 'models' => ['gpt-4.1-nano', 'gpt-4.1-mini'],
    ]);

    $url = null;
    Notification::assertSentOnDemand(CompanyInvitationNotification::class, function ($notification, $channels, $notifiable) use (&$url) {
        $url = $notification->toMail($notifiable)->viewData['url'];

        return true;
    });
    contiGuest();

    $this->post(route('company-invitation.accept', basename((string) parse_url((string) $url, PHP_URL_PATH))), [
        'name' => 'Nueva Persona', 'password' => 'Mi-Clave-Nueva-2468', 'password_confirmation' => 'Mi-Clave-Nueva-2468',
    ])->assertRedirect(route('dashboard'));

    $setting = ContiUserSetting::sole();
    expect($setting->user->email)->toBe('nueva@example.com')
        ->and($setting->license_id)->toBe($f['license']->id)
        ->and($setting->daily_credits)->toBe('10.00')
        ->and($setting->weekly_credits)->toBe('30.00')
        ->and($setting->models)->toBe(['gpt-4.1-nano', 'gpt-4.1-mini']);
});

it('los límites de una persona no pasan los de la licencia, el diario no pasa el semanal, y hace falta un modelo', function () {
    $f = contiOwner(['ai_user_daily_credits' => 50]);
    $user = contiMember($f);
    $update = fn (array $conti) => $this->put(route('users.permissions.update', $user->id), contiPermissionsUpdate([
        'enabled' => true, 'daily_credits' => null, 'weekly_credits' => null, 'models' => ['gpt-4.1-mini'], ...$conti,
    ]));

    $update(['daily_credits' => '60'])->assertSessionHasErrors(['conti.daily_credits' => 'No puede pasar lo que la licencia permite por día: 50 créditos.']);
    $update(['weekly_credits' => '500'])->assertSessionHasErrors(['conti.weekly_credits' => 'No puede pasar lo que la licencia permite por semana: 400 créditos.']);
    $update(['daily_credits' => '30', 'weekly_credits' => '20'])->assertSessionHasErrors(['conti.daily_credits' => 'El límite por día no puede ser mayor que el de la semana.']);
    $update(['models' => []])->assertSessionHasErrors(['conti.models' => 'Elegí al menos un modelo.']);
    $update(['models' => ['o1-pro']])->assertSessionHasErrors(['conti.models.0']);

    expect(ContiUserSetting::count())->toBe(0);

    $update(['daily_credits' => '20', 'weekly_credits' => '80'])->assertSessionHasNoErrors();
    expect(ContiUserSetting::sole()->only(['enabled', 'daily_credits', 'weekly_credits', 'models']))
        ->toBe(['enabled' => true, 'daily_credits' => '20.00', 'weekly_credits' => '80.00', 'models' => ['gpt-4.1-mini']]);
});

it('un Administrador no cambia lo de Conti, aunque lo mande', function () {
    $f = contiOwner();
    $admin = contiMember($f, 'admin');
    $user = contiMember($f);

    $this->actingAs($admin)
        ->put(route('users.permissions.update', $user->id), contiPermissionsUpdate(['enabled' => false, 'models' => []]))
        ->assertSessionHasNoErrors();

    expect(ContiUserSetting::count())->toBe(0);
});

it('sin acceso, Conti no aparece ni responde; con modelos elegidos, solo esos', function () {
    $f = contiOwner();
    $user = contiMember($f);
    Http::fake([
        'https://api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4.1-mini'], ['id' => 'gpt-4.1-nano'], ['id' => 'gpt-4.1']]]),
        OPENAI_URL => Http::response(openAiReply('Hola.')),
    ]);

    $this->put(route('users.permissions.update', $user->id), contiPermissionsUpdate(['enabled' => false, 'models' => []]))->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('conti.enabled', false));
    sendToConti($this, ['user' => $user])->assertForbidden()
        ->assertJson(['message' => 'No tenés acceso a Conti en esta licencia. Si lo necesitás, pedíselo al Superusuario.']);
    $this->actingAs($user)->getJson(route('conti.settings.show'))->assertForbidden();

    $this->actingAs($f['owner'])
        ->put(route('users.permissions.update', $user->id), contiPermissionsUpdate(['enabled' => true, 'models' => ['gpt-4.1-nano']]))
        ->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('conti.enabled', true)
        ->where('conti.model', 'GPT-4.1 nano'));

    $settings = $this->actingAs($user)->getJson(route('conti.settings.show'))->assertOk();
    expect($settings->json('modelo'))->toBe('gpt-4.1-nano')
        ->and(array_column($settings->json('modelos'), 'id'))->toBe(['gpt-4.1-nano'])
        ->and($settings->json('modelos_limitados'))->toBeTrue();

    $this->actingAs($user)->putJson(route('conti.settings.model'), ['modelo' => 'gpt-4.1'])->assertStatus(422);

    sendToConti($this, ['user' => $user])->assertOk();
    Http::assertSent(fn (Request $request) => $request->url() === OPENAI_URL && $request['model'] === 'gpt-4.1-nano');
});

it('el cupo es compartido: si los demás lo gastan, tampoco el Superusuario puede usar Conti', function () {
    Http::fake();
    $f = contiOwner(['ai_daily_credits' => 10]);
    $user = contiMember($f);

    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $user->id, 'model' => 'gpt-4.1-mini', 'credits' => 10, 'cost_usd' => 0.1]);

    sendToConti($this, $f)->assertStatus(429)
        ->assertJson(['message' => 'Se alcanzó el límite diario de Conti de esta licencia (10 créditos), que comparten todas sus personas. Se renueva mañana a las 00:00.']);
    Http::assertNothingSent();
});

it('los límites de la persona cortan antes que los de la licencia, y se ven en «Modelo y consumo»', function () {
    // Miércoles: el lunes ya es de esta semana.
    $this->travelTo(Carbon::parse('2026-10-07 15:00', 'America/Costa_Rica'));
    Http::fake(['https://api.openai.com/v1/models' => Http::response(['data' => []])]);
    $f = contiOwner();
    $user = contiMember($f);

    $this->put(route('users.permissions.update', $user->id), contiPermissionsUpdate([
        'enabled' => true, 'daily_credits' => '5', 'weekly_credits' => '8', 'models' => array_keys(config('conti.models')),
    ]))->assertSessionHasNoErrors();
    // Todos los modelos marcados: «todos», también los que se agreguen.
    expect(ContiUserSetting::sole()->models)->toBeNull();

    // Después, el backoffice baja el límite por persona de la licencia.
    $f['license']->update(['ai_user_daily_credits' => 3]);

    $spend = function (string $at, float $credits) use ($f, $user) {
        $row = new ContiUsage(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $user->id, 'model' => 'gpt-4.1-mini', 'credits' => $credits, 'cost_usd' => $credits / 100]);
        $row->created_at = Carbon::parse($at, 'America/Costa_Rica')->utc();
        $row->save();
    };

    $spend('2026-10-05 10:00', 7);
    $spend('2026-10-07 09:00', 1);

    $settings = $this->actingAs($user)->getJson(route('conti.settings.show'))->assertOk();
    expect(array_column($settings->json('limites'), 'clave'))->toBe(['user_daily', 'user_weekly', 'daily', 'weekly'])
        // Por día cuenta el más chico: el de persona del backoffice (3) le gana al del Superusuario (5).
        ->and($settings->json('limites.0.limite'))->toBe('3.00')
        ->and($settings->json('limites.1.usado'))->toBe('8.00');

    sendToConti($this, ['user' => $user])->assertStatus(429)
        ->assertJson(['message' => 'Llegaste a tu límite semanal de Conti (8 créditos). Se renueva el lunes a las 00:00.']);
});

it('al Superusuario no se lo limita, y en Usuarios ve lo de Conti de cada persona', function () {
    $f = contiOwner();
    $user = contiMember($f);
    $admin = contiMember($f, 'admin');

    // Aunque se intente, al Superusuario no se le guarda nada.
    $this->put(route('users.permissions.update', $f['owner']->id), contiPermissionsUpdate(['enabled' => false, 'models' => []]));
    expect(ContiUserSetting::count())->toBe(0);

    $this->put(route('users.permissions.update', $user->id), contiPermissionsUpdate(['enabled' => true, 'daily_credits' => '5', 'models' => ['gpt-4.1-mini']]));
    ContiUsage::create(['license_id' => $f['license']->id, 'company_id' => $f['company']->id, 'user_id' => $user->id, 'model' => 'gpt-4.1-mini', 'credits' => 2.5, 'cost_usd' => 0.025]);

    $this->get(route('users.index'))->assertInertia(fn ($page) => $page
        ->where('users', fn ($users) => collect($users)->firstWhere('id', $user->id)['conti'] === [
            'enabled' => true, 'daily_credits' => '5.00', 'weekly_credits' => null, 'models' => ['GPT-4.1 mini'],
            'superuser' => false, 'used_today' => '2.50', 'used_week' => '2.50',
        ] && collect($users)->firstWhere('id', $f['owner']->id)['conti']['superuser'] === true));

    $this->actingAs($admin)->get(route('users.index'))
        ->assertInertia(fn ($page) => $page->where('users', fn ($users) => collect($users)->every(fn ($u) => $u['conti'] === null)));
});
