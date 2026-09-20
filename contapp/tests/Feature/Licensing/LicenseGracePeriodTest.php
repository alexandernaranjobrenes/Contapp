<?php

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;

it('isExpiredButActive es verdadero solo para una licencia vencida y no revocada', function () {
    $active = License::factory()->create();
    $expired = License::factory()->expired()->create();
    $revokedAndExpired = License::factory()->expired()->revoked()->create();

    expect($active->isExpiredButActive())->toBeFalse()
        ->and($expired->isExpiredButActive())->toBeTrue()
        ->and($revokedAndExpired->isExpiredButActive())->toBeFalse();
});

it('maskedCode oculta los dos segmentos del medio del código de licencia', function () {
    $license = License::factory()->create(['code' => 'CONTAPP-AB12-CD34-EF56']);

    expect($license->maskedCode())->toBe('CONTAPP-****-****-EF56');
});

it('CurrentCompany::clear() también apaga el modo de gracia', function () {
    $currentCompany = new CurrentCompany;
    $currentCompany->set(1);
    $currentCompany->setGraceMode(true);

    expect($currentCompany->isInGracePeriod())->toBeTrue();

    $currentCompany->clear();

    expect($currentCompany->isInGracePeriod())->toBeFalse()
        ->and($currentCompany->isSet())->toBeFalse();
});
