<?php

use App\Domains\Conti\Models\ContiUsage;
use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Conti en el backoffice: activarlo por licencia, sus límites y su consumo
|--------------------------------------------------------------------------
*/

it('el Propietario activa Conti en una licencia y le pone límites, y queda en el historial', function () {
    loginAsPropietario();
    $license = License::factory()->create();

    $this->put(route('backoffice.licenses.update', $license->id), [
        'category_id' => $license->category_id ?? LicenseCategory::factory()->create()->id,
        'max_companies' => $license->max_companies,
        'max_admins' => $license->max_admins,
        'max_users' => $license->max_users,
        'ai_enabled' => true,
        'ai_daily_credits' => '50',
        'ai_weekly_credits' => '',
        'ai_user_daily_credits' => '10.5',
    ])->assertSessionHasNoErrors();

    $license->refresh();
    expect($license->ai_enabled)->toBeTrue()
        ->and((string) $license->ai_daily_credits)->toBe('50.00')
        ->and($license->ai_weekly_credits)->toBeNull()
        ->and((string) $license->ai_user_daily_credits)->toBe('10.50');

    $entry = AuditLog::where('action', 'license_updated')->latest('id')->first();
    expect($entry->new_values)->toMatchArray(['ai_enabled' => true, 'ai_daily_credits' => '50.00', 'ai_user_daily_credits' => '10.50']);
});

it('un límite negativo no se acepta', function () {
    loginAsPropietario();
    $license = License::factory()->create(['category_id' => LicenseCategory::factory()->create()->id]);

    $this->put(route('backoffice.licenses.update', $license->id), [
        'category_id' => $license->category_id, 'max_companies' => 1, 'max_admins' => 3, 'max_users' => 10,
        'ai_enabled' => true, 'ai_daily_credits' => '-5',
    ])->assertSessionHasErrors('ai_daily_credits');
});

it('una licencia nueva nace con los valores de Conti de su categoría', function () {
    loginAsPropietario();
    $category = LicenseCategory::factory()->create(['ai_enabled' => true, 'ai_weekly_credits' => 300, 'ai_user_daily_credits' => 20]);

    $this->post(route('backoffice.licenses.store'), [
        'category_id' => $category->id,
        'expires_at' => now()->addYear()->toDateString(),
    ])->assertSessionHasNoErrors();

    $license = License::latest('id')->first();
    expect($license->ai_enabled)->toBeTrue()
        ->and((string) $license->ai_weekly_credits)->toBe('300.00')
        ->and((string) $license->ai_user_daily_credits)->toBe('20.00')
        ->and($license->ai_daily_credits)->toBeNull();
});

it('la categoría guarda sus valores de Conti', function () {
    loginAsPropietario();
    $category = LicenseCategory::factory()->create();

    $this->put(route('backoffice.license-categories.update', $category->id), [
        'name' => $category->name, 'max_companies' => 1, 'max_admins' => 3, 'max_users' => 10, 'duration_months' => 12,
        'is_active' => true, 'ai_enabled' => true, 'ai_daily_credits' => '25',
    ])->assertSessionHasNoErrors();

    expect($category->fresh()->ai_enabled)->toBeTrue()
        ->and((string) $category->fresh()->ai_daily_credits)->toBe('25.00');
});

it('la ficha muestra el consumo de hoy y de la semana, y el detalle por compañía y persona', function () {
    loginAsPropietario();
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    $license = License::factory()->create(['superuser_id' => $user->id, 'ai_enabled' => true, 'ai_daily_credits' => 100]);
    $company->update(['license_id' => $license->id]);

    ContiUsage::create(['license_id' => $license->id, 'company_id' => $company->id, 'user_id' => $user->id, 'model' => 'gpt-4.1-mini', 'credits' => 12.5, 'cost_usd' => 0.125]);
    ContiUsage::create(['license_id' => $license->id, 'company_id' => $company->id, 'user_id' => $user->id, 'model' => 'gpt-4.1-mini', 'credits' => 2.5, 'cost_usd' => 0.025, 'status' => 'error']);

    $this->get(route('backoffice.licenses.index'))->assertInertia(fn ($page) => $page
        ->where('licenses.0.ai_today', '15.00')
        ->where('licenses.0.ai_week', '15.00'));

    $report = $this->getJson(route('backoffice.licenses.ai-usage', $license->id))->assertOk()->json();

    expect($report['hoy'])->toBe(['mensajes' => 2, 'creditos' => '15.0000'])
        ->and($report['treinta_dias']['costo_usd'])->toBe('0.1500')
        ->and($report['treinta_dias']['errores'])->toBe(1)
        ->and($report['limites'][0])->toMatchArray(['clave' => 'daily', 'limite' => '100.00', 'usado' => '15.00'])
        ->and($report['por_compania'][0]['creditos'])->toBe('15.00')
        ->and($report['por_persona'][0]['nombre'])->toBe($user->name)
        ->and($report['por_dia'])->toHaveCount(30);
});

it('el consumo de una licencia no se ve sin ser Propietario', function () {
    $license = License::factory()->create();

    // Como el historial de la licencia: sin sesión del backoffice, a su login.
    $this->get(route('backoffice.licenses.ai-usage', $license->id))->assertRedirect(route('backoffice.login'));

    ['user' => $user] = contiUser([], null, true);
    $this->actingAs($user)->get(route('backoffice.licenses.ai-usage', $license->id))->assertRedirect(route('backoffice.login'));
});

it('el costo se calcula con el precio del modelo, por el prefijo más largo', function () {
    $usage = app(ContiUsageService::class);

    expect($usage->pricingFor('gpt-4.1-mini-2025-04-14'))->toBe(config('conti.pricing')['gpt-4.1-mini'])
        ->and($usage->pricingFor('gpt-4.1-2025-04-14'))->toBe(config('conti.pricing')['gpt-4.1'])
        ->and($usage->pricingFor('modelo-desconocido'))->toBe(config('conti.fallback_pricing'))
        // 1M de entrada a 0,40 + 1M de salida a 1,60.
        ->and($usage->cost('gpt-4.1-mini', 1_000_000, 0, 1_000_000))->toBe('2.000000');
});
