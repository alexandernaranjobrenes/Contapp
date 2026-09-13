<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\CashFlowProjectionService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function cashFlowFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001']);
    $cxp = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '2-01-01-01-001',
        'account_type' => 'liability', 'normal_balance' => 'credit',
    ]);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '6-01-01-01-001',
        'account_type' => 'expense', 'normal_balance' => 'debit',
    ]);

    $client = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);
    $supplier = BusinessPartner::factory()->supplier()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxp->id, 'currency_id' => $company->local_currency_id,
    ]);

    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('company', 'cxc', 'cxp', 'sales', 'expense', 'client', 'supplier', 'documentType');
}

function postClientReceivable(array $fx, BusinessPartner $client, string $amount, string $dueDate): void
{
    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-01'), new DateTime('2026-01-01'),
        [
            new JournalLineInput(
                $fx['cxc']->id, $client->currency_id, debit: $amount, credit: 0,
                businessPartnerId: $client->id, dueDate: $dueDate, opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $client->currency_id, debit: 0, credit: $amount),
        ],
    );
}

function postSupplierPayable(array $fx, BusinessPartner $supplier, string $amount, string $dueDate): void
{
    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-01'), new DateTime('2026-01-01'),
        [
            new JournalLineInput($fx['expense']->id, $supplier->currency_id, debit: $amount, credit: 0),
            new JournalLineInput(
                $fx['cxp']->id, $supplier->currency_id, debit: 0, credit: $amount,
                businessPartnerId: $supplier->id, dueDate: $dueDate, opensItem: true,
            ),
        ],
    );
}

it('clasifica cobros y pagos por la naturaleza de la cuenta de origen, no por el tipo del socio', function () {
    $fx = cashFlowFixture();

    postClientReceivable($fx, $fx['client'], '100', '2026-01-10'); // 9 días hacia adelante
    postSupplierPayable($fx, $fx['supplier'], '50', '2025-12-01'); // vencido

    $result = app(CashFlowProjectionService::class)->build($fx['company'], '2026-01-01');

    expect($result->collections)->toHaveCount(1)
        ->and($result->collections[0]->bucketTotals['d_0_15'])->toEqual('100.00')
        ->and($result->payments)->toHaveCount(1)
        ->and($result->payments[0]->bucketTotals['overdue'])->toEqual('50.00');
});

it('clasifica en los buckets hacia adelante correctos según días hasta el vencimiento', function () {
    $fx = cashFlowFixture();

    postClientReceivable($fx, $fx['client'], '10', '2025-12-01'); // vencido
    postClientReceivable($fx, $fx['client'], '20', '2026-01-10'); // 9 días
    postClientReceivable($fx, $fx['client'], '30', '2026-01-25'); // 24 días
    postClientReceivable($fx, $fx['client'], '40', '2026-02-20'); // 50 días
    postClientReceivable($fx, $fx['client'], '50', '2026-03-15'); // 73 días
    postClientReceivable($fx, $fx['client'], '60', '2026-06-01'); // >90 días

    $result = app(CashFlowProjectionService::class)->build($fx['company'], '2026-01-01');
    $row = $result->collections[0]->rows[0];

    expect($row->buckets['overdue'])->toEqual('10.00')
        ->and($row->buckets['d_0_15'])->toEqual('20.00')
        ->and($row->buckets['d_16_30'])->toEqual('30.00')
        ->and($row->buckets['d_31_60'])->toEqual('40.00')
        ->and($row->buckets['d_61_90'])->toEqual('50.00')
        ->and($row->buckets['over'])->toEqual('60.00')
        ->and($row->total)->toEqual('210.00')
        ->and($row->documents)->toHaveCount(6);
});

it('permite personalizar los cortes de días de la proyección en vez del estándar 15/30/60/90', function () {
    $fx = cashFlowFixture();

    postClientReceivable($fx, $fx['client'], '10', '2026-01-05'); // 4 días
    postClientReceivable($fx, $fx['client'], '20', '2026-02-01'); // 31 días

    $result = app(CashFlowProjectionService::class)->build($fx['company'], '2026-01-01', '10,40');

    expect($result->bucketLabels)->toEqual([
        'overdue' => 'Vencido',
        'd_0_10' => '0-10 días',
        'd_11_40' => '11-40 días',
        'over' => '+40 días',
    ]);

    $row = $result->collections[0]->rows[0];
    expect($row->buckets['d_0_10'])->toEqual('10.00')
        ->and($row->buckets['d_11_40'])->toEqual('20.00');
});

it('aísla la proyección de cobros y pagos entre compañías distintas', function () {
    $fxA = cashFlowFixture();
    postClientReceivable($fxA, $fxA['client'], '100', '2026-01-10');

    $fxB = cashFlowFixture();
    postClientReceivable($fxB, $fxB['client'], '900', '2026-01-10');

    $result = app(CashFlowProjectionService::class)->build($fxA['company'], '2026-01-01');

    expect($result->collections[0]->total)->toEqual('100.00');
});
