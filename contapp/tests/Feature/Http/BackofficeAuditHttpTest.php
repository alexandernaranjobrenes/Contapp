<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;

/**
 * Bitácora de las acciones del Propietario (RecordsPropietarioAudit): quién
 * hizo cada cosa queda en propietario_id, sin compañía ni usuario de
 * compañía, y la clave de una licencia nunca se guarda en ella.
 */
function auditActions(): array
{
    return AuditLog::orderBy('id')->pluck('action')->all();
}

it('registra quién vio la clave completa de una licencia, sin guardar la clave', function () {
    $propietario = loginAsPropietario();
    $license = License::factory()->create();

    $this->getJson(route('backoffice.licenses.code', $license->id))->assertOk();

    $log = AuditLog::sole();

    expect($log->action)->toBe('license_code_revealed')
        ->and($log->propietario_id)->toBe($propietario->id)
        ->and($log->company_id)->toBeNull()
        ->and($log->user_id)->toBeNull()
        ->and($log->auditable_type)->toBe(License::class)
        ->and($log->auditable_id)->toBe($license->id)
        ->and($log->ip_address)->toBe('127.0.0.1')
        ->and(json_encode($log->getAttributes()))->not->toContain($license->code);
});

it('registra el ciclo de vida de una licencia, con el estado de antes y el de después', function () {
    $propietario = loginAsPropietario();
    $category = LicenseCategory::factory()->create(['max_companies' => 3]);

    $this->post(route('backoffice.licenses.store'), [
        'category_id' => $category->id,
        'expires_at' => now()->addYear()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    $license = License::sole();

    $this->post(route('backoffice.licenses.renew', $license->id), ['expires_at' => now()->addYears(2)->format('Y-m-d')]);
    $this->post(route('backoffice.licenses.suspend', $license->id));
    $this->post(route('backoffice.licenses.reactivate', $license->id));
    $this->post(route('backoffice.licenses.revoke', $license->id));

    expect(auditActions())->toBe([
        'license_issued', 'license_renewed', 'license_suspended', 'license_reactivated', 'license_revoked',
    ]);

    $issued = AuditLog::where('action', 'license_issued')->sole();
    expect($issued->new_values)->toMatchArray([
        'category_id' => $category->id,
        'max_companies' => 3,
        'expires_at' => now()->addYear()->format('Y-m-d'),
    ]);

    $renewed = AuditLog::where('action', 'license_renewed')->sole();
    expect($renewed->old_values['expires_at'])->toBe(now()->addYear()->format('Y-m-d'))
        ->and($renewed->new_values['expires_at'])->toBe(now()->addYears(2)->format('Y-m-d'));

    $suspended = AuditLog::where('action', 'license_suspended')->sole();
    expect($suspended->old_values)->toBe(['status' => 'active'])
        ->and($suspended->new_values)->toBe(['status' => 'suspended']);

    // Todas del mismo Propietario, y ninguna guarda la clave.
    expect(AuditLog::pluck('propietario_id')->unique()->values()->all())->toBe([$propietario->id])
        ->and(AuditLog::all()->contains(fn ($log) => str_contains(json_encode($log->getAttributes()), $license->code)))->toBeFalse();
});

it('al editar una licencia registra solo lo que cambió, y nada si no cambió nada', function () {
    loginAsPropietario();
    $category = LicenseCategory::factory()->create();
    $license = License::factory()->create(['category_id' => $category->id, 'max_users' => 10, 'notes' => 'Convenio anual']);

    $payload = [
        'category_id' => $category->id,
        'max_companies' => $license->max_companies,
        'max_admins' => $license->max_admins,
        'max_users' => 10,
        'notes' => 'Convenio anual',
    ];

    $this->put(route('backoffice.licenses.update', $license->id), $payload)->assertSessionHasNoErrors();

    expect(AuditLog::count())->toBe(0);

    $this->put(route('backoffice.licenses.update', $license->id), [...$payload, 'max_users' => 20])->assertSessionHasNoErrors();

    $log = AuditLog::sole();
    expect($log->action)->toBe('license_updated')
        ->and($log->old_values)->toBe(['max_users' => 10])
        ->and($log->new_values)->toBe(['max_users' => 20]);
});

it('si la edición se rechaza, no queda entrada en la bitácora', function () {
    loginAsPropietario();
    $category = LicenseCategory::factory()->create();
    $license = License::factory()->create(['category_id' => $category->id, 'max_companies' => 2]);
    Company::factory()->count(2)->create(['license_id' => $license->id]);

    // Bajar el cupo por debajo de las compañías activas lo rechaza el servicio.
    $this->put(route('backoffice.licenses.update', $license->id), [
        'category_id' => $category->id,
        'max_companies' => 1,
        'max_admins' => $license->max_admins,
        'max_users' => $license->max_users,
    ])->assertSessionHasErrors('license');

    expect(AuditLog::count())->toBe(0)
        ->and($license->fresh()->max_companies)->toBe(2);
});

it('registra los cambios del Propietario en las categorías de licencia', function () {
    loginAsPropietario();

    $payload = [
        'name' => 'Básica',
        'max_companies' => 1,
        'max_admins' => 2,
        'max_users' => 5,
        'duration_months' => 12,
        'description' => 'Para empezar',
        'is_active' => true,
    ];

    $this->post(route('backoffice.license-categories.store'), $payload)->assertSessionHasNoErrors();
    $category = LicenseCategory::sole();

    $this->put(route('backoffice.license-categories.update', $category->id), [...$payload, 'name' => 'Básica Plus', 'max_users' => 8])
        ->assertSessionHasNoErrors();
    $this->delete(route('backoffice.license-categories.destroy', $category->id))->assertSessionHasNoErrors();

    expect(auditActions())->toBe(['license_category_created', 'license_category_updated', 'license_category_deleted']);

    $updated = AuditLog::where('action', 'license_category_updated')->sole();
    expect($updated->old_values)->toBe(['name' => 'Básica', 'max_users' => 5])
        ->and($updated->new_values)->toBe(['name' => 'Básica Plus', 'max_users' => 8]);

    // La categoría ya no existe: la foto en old_values dice cuál era.
    $deleted = AuditLog::where('action', 'license_category_deleted')->sole();
    expect(LicenseCategory::find($category->id))->toBeNull()
        ->and($deleted->old_values['name'])->toBe('Básica Plus');
});

it('registra los cambios del Propietario en el catálogo nacional de IVA', function () {
    $propietario = loginAsPropietario();
    $type = TaxType::factory()->create(['code' => 'IVA']);

    $payload = [
        'tax_type_id' => $type->id,
        'code' => 'IVA-1',
        'name' => 'IVA tarifa reducida 1%',
        'percentage' => '1.00',
        'effective_from' => '2020-01-01',
    ];

    $this->post(route('backoffice.tax-rates.store'), $payload)->assertSessionHasNoErrors();
    $rate = TaxRate::where('code', 'IVA-1')->sole();

    // Mismo porcentaje escrito de otra forma: no es un cambio.
    $this->put(route('backoffice.tax-rates.update', $rate->id), [...$payload, 'percentage' => '1'])->assertSessionHasNoErrors();
    $this->put(route('backoffice.tax-rates.update', $rate->id), [...$payload, 'percentage' => '2.00'])->assertSessionHasNoErrors();
    $this->delete(route('backoffice.tax-rates.destroy', $rate->id))->assertSessionHasNoErrors();

    expect(auditActions())->toBe(['tax_rate_created', 'tax_rate_updated', 'tax_rate_deleted']);

    $created = AuditLog::where('action', 'tax_rate_created')->sole();
    expect($created->propietario_id)->toBe($propietario->id)
        ->and($created->new_values['effective_from'])->toBe('2020-01-01');

    $updated = AuditLog::where('action', 'tax_rate_updated')->sole();
    expect($updated->old_values)->toBe(['percentage' => '1.00'])
        ->and($updated->new_values)->toBe(['percentage' => '2.00']);

    $deleted = AuditLog::where('action', 'tax_rate_deleted')->sole();
    expect($deleted->old_values['code'])->toBe('IVA-1');
});

it('el historial de la ficha trae los movimientos de la licencia, del más reciente al más antiguo', function () {
    loginAsPropietario(['name' => 'Ana Propietaria']);
    $basica = LicenseCategory::factory()->create(['name' => 'Básica']);
    $profesional = LicenseCategory::factory()->create(['name' => 'Profesional']);

    $this->post(route('backoffice.licenses.store'), [
        'category_id' => $basica->id,
        'expires_at' => now()->addYear()->format('Y-m-d'),
    ]);
    $license = License::sole();

    $this->put(route('backoffice.licenses.update', $license->id), [
        'category_id' => $profesional->id,
        'max_companies' => $license->max_companies,
        'max_admins' => $license->max_admins,
        'max_users' => $license->max_users,
    ]);
    $this->post(route('backoffice.licenses.suspend', $license->id));
    $this->getJson(route('backoffice.licenses.code', $license->id));

    $response = $this->getJson(route('backoffice.licenses.history', $license->id))->assertOk();
    $history = $response->json();

    expect(array_column($history['entries'], 'action'))->toBe([
        'license_code_revealed', 'license_suspended', 'license_updated', 'license_issued',
    ])
        ->and($history['entries'][0]['actor'])->toBe('Ana Propietaria')
        ->and($history['entries'][0]['ip'])->toBe('127.0.0.1')
        // UTC con su zona: el navegador la pasa a la hora local de quien mira.
        ->and($history['entries'][0]['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and($history['entries'][0]['changes'])->toBe([])
        ->and($history['entries'][1]['changes'])->toBe([['field' => 'status', 'from' => 'active', 'to' => 'suspended']])
        // La categoría va por nombre, no por id.
        ->and($history['entries'][2]['changes'])->toBe([['field' => 'category_id', 'from' => 'Básica', 'to' => 'Profesional']])
        ->and($history['entries'][3]['changes'])->toContain(['field' => 'category_id', 'from' => null, 'to' => 'Básica'])
        ->and($history['has_more'])->toBeFalse()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(json_encode($history))->not->toContain($license->code);
});

it('el historial de una licencia no trae los movimientos de otra', function () {
    loginAsPropietario();
    [$una, $otra] = License::factory()->count(2)->create();

    $this->getJson(route('backoffice.licenses.code', $una->id));
    $this->getJson(route('backoffice.licenses.code', $otra->id));
    $this->getJson(route('backoffice.licenses.code', $otra->id));

    expect($this->getJson(route('backoffice.licenses.history', $una->id))->json('entries'))->toHaveCount(1)
        ->and($this->getJson(route('backoffice.licenses.history', $otra->id))->json('entries'))->toHaveCount(2);
});

it('el historial trae hasta 50 movimientos y avisa cuando hay más', function () {
    $propietario = loginAsPropietario();
    $license = License::factory()->create();

    foreach (range(1, 51) as $i) {
        AuditLog::create([
            'propietario_id' => $propietario->id,
            'action' => 'license_code_revealed',
            'auditable_type' => License::class,
            'auditable_id' => $license->id,
            'created_at' => now(),
        ]);
    }

    $history = $this->getJson(route('backoffice.licenses.history', $license->id))->json();

    expect($history['entries'])->toHaveCount(50)
        ->and($history['has_more'])->toBeTrue();
});

it('rechaza ver el historial a un usuario de compañía: no es Propietario', function () {
    logInAsCompanyUser();
    $license = License::factory()->create();

    $this->getJson(route('backoffice.licenses.history', $license->id))
        ->assertRedirect(route('backoffice.login'));
});
