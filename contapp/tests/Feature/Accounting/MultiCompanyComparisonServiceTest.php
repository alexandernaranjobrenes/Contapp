<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\MultiCompanyComparisonService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function comparisonCompanyFixture(string $capitalAmount, string $saleAmount): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $capital = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '3-01-01-01-001',
        'account_type' => 'equity', 'normal_balance' => 'credit',
    ]);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);

    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    $post = fn (ChartOfAccount $debit, ChartOfAccount $credit, string $amount) => app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput($debit->id, $company->local_currency_id, debit: $amount, credit: 0),
            new JournalLineInput($credit->id, $company->local_currency_id, debit: 0, credit: $amount),
        ],
    );

    $post($cash, $capital, $capitalAmount);
    $post($cash, $sales, $saleAmount);

    return compact('company', 'cash', 'capital', 'sales');
}

it('compara varias compañías del mismo grupo sin mezclar sus cifras', function () {
    $fxA = comparisonCompanyFixture('5000', '1000');
    $fxB = comparisonCompanyFixture('9000', '2500');

    $result = app(MultiCompanyComparisonService::class)->build(
        collect([$fxA['company'], $fxB['company']]), '2026-01-31', '2026-01-01', '2026-01-31'
    );

    expect($result->rows)->toHaveCount(2);

    $rowA = collect($result->rows)->firstWhere('companyId', $fxA['company']->id);
    $rowB = collect($result->rows)->firstWhere('companyId', $fxB['company']->id);

    expect($rowA->assetsTotal)->toEqual('6000.00')
        ->and($rowA->salesTotal)->toEqual('1000.00')
        ->and($rowB->assetsTotal)->toEqual('11500.00')
        ->and($rowB->salesTotal)->toEqual('2500.00');
});

it('cada fila trae la moneda local de su propia compañía', function () {
    $fx = comparisonCompanyFixture('1000', '500');

    $result = app(MultiCompanyComparisonService::class)->build(
        collect([$fx['company']]), '2026-01-31', '2026-01-01', '2026-01-31'
    );

    expect($result->rows[0]->currencyCode)->toBe($fx['company']->localCurrency->code);
});
