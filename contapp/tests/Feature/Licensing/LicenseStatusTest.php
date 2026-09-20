<?php

use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;
use App\Domains\Licensing\Services\LicenseService;

it('displayStatus refleja revocada/suspendida por encima de cualquier fecha', function () {
    $revoked = License::factory()->expired()->revoked()->create();
    $suspended = License::factory()->suspended()->create();

    expect($revoked->displayStatus())->toBe('revoked')
        ->and($suspended->displayStatus())->toBe('suspended');
});

it('displayStatus distingue vigente, por vencer y vencida a partir de la fecha, sin almacenarlas', function () {
    $active = License::factory()->create(['expires_at' => now()->addMonths(6)->format('Y-m-d')]);
    $expiringSoon = License::factory()->create(['expires_at' => now()->addDays(10)->format('Y-m-d')]);
    $expired = License::factory()->expired()->create();

    expect($active->displayStatus())->toBe('active')
        ->and($expiringSoon->displayStatus())->toBe('expiring_soon')
        ->and($expired->displayStatus())->toBe('expired');
});

it('isBlocked es verdadero para suspendida y revocada, falso para activa o vencida', function () {
    expect(License::factory()->suspended()->create()->isBlocked())->toBeTrue()
        ->and(License::factory()->revoked()->create()->isBlocked())->toBeTrue()
        ->and(License::factory()->create()->isBlocked())->toBeFalse()
        ->and(License::factory()->expired()->create()->isBlocked())->toBeFalse();
});

it('una licencia guarda el max_companies de su categoría al emitirse, sin quedar atada a cambios futuros de la categoría', function () {
    $category = LicenseCategory::factory()->create(['max_companies' => 5]);

    $license = app(LicenseService::class)->issue(
        $category, new DateTime('+1 year'), null, null
    );

    expect($license->max_companies)->toBe(5)
        ->and($license->category_id)->toBe($category->id);

    $category->update(['max_companies' => 20]);

    expect($license->fresh()->max_companies)->toBe(5);
});
