<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\OpenItemBackfillService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;

function backfillFixture(): array
{
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company);

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001']);
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('company', 'cash', 'cxc', 'documentType');
}

// Simula el caso real: una línea con socio se contabilizó SIN opensItem
// (sin due_date propio) — exactamente lo que dejaba PostJournalService
// antes de que existiera esta reparación.
function postLoanWithoutOpeningItem(array $fx, BusinessPartner $partner, string $amount): JournalEntry
{
    return app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-13'), new DateTime('2026-01-31'),
        [
            new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: $amount, credit: 0, businessPartnerId: $partner->id),
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: $amount),
        ],
        'Préstamo sin partida'
    );
}

it('abre partidas retroactivas para varios socios de negocio distintos, calculando el vencimiento desde sus días de crédito', function () {
    $fx = backfillFixture();

    $partnerA = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'code' => 'A-01', 'gl_account_id' => $fx['cxc']->id, 'payment_terms_days' => 30]);
    $partnerB = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'code' => 'B-01', 'gl_account_id' => $fx['cxc']->id, 'payment_terms_days' => 60]);

    postLoanWithoutOpeningItem($fx, $partnerA, '1000');
    postLoanWithoutOpeningItem($fx, $partnerB, '2000');

    $result = app(OpenItemBackfillService::class)->run($fx['company']);

    expect($result['created'])->toHaveCount(2)
        ->and($result['skipped'])->toBe([]);

    $itemA = BpOpenItem::where('business_partner_id', $partnerA->id)->sole();
    $itemB = BpOpenItem::where('business_partner_id', $partnerB->id)->sole();

    // document_date de las líneas es 2026-01-13 (ver postLoanWithoutOpeningItem) — 30/60 días desde ahí.
    expect($itemA->due_date->format('Y-m-d'))->toBe('2026-02-12')
        ->and($itemA->balance)->toEqual('1000.00')
        ->and($itemA->status)->toBe('open')
        ->and($itemB->due_date->format('Y-m-d'))->toBe('2026-03-14')
        ->and($itemB->balance)->toEqual('2000.00');
});

it('omite una línea sin vencimiento propio cuando el socio no tiene días de crédito configurados', function () {
    $fx = backfillFixture();
    $partner = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'code' => 'C-01', 'gl_account_id' => $fx['cxc']->id, 'payment_terms_days' => null]);

    postLoanWithoutOpeningItem($fx, $partner, '500');

    $result = app(OpenItemBackfillService::class)->run($fx['company']);

    expect($result['created'])->toBe([])
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]['partner_code'])->toBe('C-01');

    expect(BpOpenItem::count())->toBe(0);
});

it('en modo dry-run no escribe nada en la base de datos', function () {
    $fx = backfillFixture();
    $partner = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'code' => 'D-01', 'gl_account_id' => $fx['cxc']->id, 'payment_terms_days' => 15]);

    $entry = postLoanWithoutOpeningItem($fx, $partner, '700');

    $result = app(OpenItemBackfillService::class)->run($fx['company'], dryRun: true);

    expect($result['created'])->toHaveCount(1)
        ->and(BpOpenItem::count())->toBe(0)
        ->and($entry->details->firstWhere('business_partner_id', $partner->id)->fresh()->due_date)->toBeNull();
});

it('no duplica una partida que ya existe, y es idempotente al volver a correr', function () {
    $fx = backfillFixture();
    $partner = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'code' => 'E-01', 'gl_account_id' => $fx['cxc']->id, 'payment_terms_days' => 30]);

    postLoanWithoutOpeningItem($fx, $partner, '900');

    $service = app(OpenItemBackfillService::class);
    $service->run($fx['company']);
    $secondRun = $service->run($fx['company']);

    expect(BpOpenItem::count())->toBe(1)
        ->and($secondRun['created'])->toBe([]);
});

it('no toca líneas cuyo asiento todavía está en borrador', function () {
    $fx = backfillFixture();
    $partner = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'code' => 'F-01', 'gl_account_id' => $fx['cxc']->id, 'payment_terms_days' => 30]);

    app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-13'), new DateTime('2026-01-31'),
        [
            new JournalLineInput($fx['cxc']->id, $fx['company']->local_currency_id, debit: '400', credit: 0, businessPartnerId: $partner->id, allowZeroAmount: true),
        ],
        'Borrador sin contabilizar'
    );

    $result = app(OpenItemBackfillService::class)->run($fx['company']);

    expect($result['created'])->toBe([])
        ->and($result['skipped'])->toBe([])
        ->and(BpOpenItem::count())->toBe(0);
});
