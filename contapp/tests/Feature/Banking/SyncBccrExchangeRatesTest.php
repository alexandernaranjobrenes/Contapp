<?php

use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Banking\Contracts\BccrExchangeRateClient;
use App\Domains\Banking\Services\SyncBccrExchangeRatesService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;

function unscopedExchangeRates(): Builder
{
    return ExchangeRate::withoutGlobalScope(CompanyScope::class);
}

function bindFakeBccrClient(?string $rate): void
{
    app()->bind(BccrExchangeRateClient::class, fn () => new class($rate) implements BccrExchangeRateClient
    {
        public function __construct(private readonly ?string $rate) {}

        public function fetchRate(string $currencyCode, DateTimeInterface $date): ?string
        {
            return $currencyCode === 'USD' ? $this->rate : null;
        }
    });
}

it('crea un tipo de cambio con source bccr_api cuando el cliente devuelve un dato', function () {
    bindFakeBccrClient('525.750000');

    $company = Company::factory()->create();

    $result = app(SyncBccrExchangeRatesService::class)->syncForCompany($company, new DateTime('2026-02-01'));

    expect($result)->not->toBeNull()
        ->and($result->source)->toBe('bccr_api')
        ->and((string) $result->rate)->toEqual('525.750000');

    expect(unscopedExchangeRates()->count())->toBe(1);
});

it('no crea nada cuando el cliente no tiene dato para la fecha', function () {
    bindFakeBccrClient(null);

    $company = Company::factory()->create();

    $result = app(SyncBccrExchangeRatesService::class)->syncForCompany($company, new DateTime('2026-02-01'));

    expect($result)->toBeNull();
    expect(unscopedExchangeRates()->count())->toBe(0);
});

it('es idempotente: correrlo dos veces el mismo día actualiza en vez de duplicar', function () {
    $company = Company::factory()->create();

    bindFakeBccrClient('520.000000');
    app(SyncBccrExchangeRatesService::class)->syncForCompany($company, new DateTime('2026-02-01'));

    bindFakeBccrClient('521.000000');
    app(SyncBccrExchangeRatesService::class)->syncForCompany($company, new DateTime('2026-02-01'));

    expect(unscopedExchangeRates()->count())->toBe(1);
    expect((string) unscopedExchangeRates()->sole()->rate)->toEqual('521.000000');
});

it('no sobrescribe un tipo de cambio que quedó bloqueado por una corrección manual', function () {
    $company = Company::factory()->create();

    bindFakeBccrClient('520.000000');
    $first = app(SyncBccrExchangeRatesService::class)->syncForCompany($company, new DateTime('2026-02-01'));
    $first->update(['is_locked' => true, 'rate' => '999.000000', 'source' => 'manual']);

    bindFakeBccrClient('521.000000');
    $result = app(SyncBccrExchangeRatesService::class)->syncForCompany($company, new DateTime('2026-02-01'));

    expect(unscopedExchangeRates()->count())->toBe(1)
        ->and((string) $result->rate)->toEqual('999.000000')
        ->and($result->is_locked)->toBeTrue();
});
