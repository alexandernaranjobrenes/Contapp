<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\BalanceSheetService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function balanceSheetFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $payable = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '2-01-01-01-001',
        'account_type' => 'liability', 'normal_balance' => 'credit',
    ]);
    $capital = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '3-01-01-01-001',
        'account_type' => 'equity', 'normal_balance' => 'credit',
    ]);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '6-01-01-01-001',
        'account_type' => 'expense', 'normal_balance' => 'debit',
    ]);

    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('company', 'cash', 'payable', 'capital', 'sales', 'expense', 'documentType');
}

function postBs(array $fx, ChartOfAccount $debitAccount, ChartOfAccount $creditAccount, string $amount, string $date = '2026-01-15'): void
{
    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime($date), new DateTime($date),
        [
            new JournalLineInput($debitAccount->id, $fx['company']->local_currency_id, debit: $amount, credit: 0),
            new JournalLineInput($creditAccount->id, $fx['company']->local_currency_id, debit: 0, credit: $amount),
        ],
    );
}

it('cuadra activo contra pasivo + patrimonio, incluyendo la utilidad del ejercicio como plug de patrimonio mientras el año sigue abierto', function () {
    $fx = balanceSheetFixture();

    postBs($fx, $fx['cash'], $fx['capital'], '5000');
    postBs($fx, $fx['cash'], $fx['payable'], '2000');
    postBs($fx, $fx['cash'], $fx['sales'], '1000');
    postBs($fx, $fx['expense'], $fx['cash'], '300');

    $result = app(BalanceSheetService::class)->build($fx['company'], '2026-01-31');

    expect($result->assetsTotal)->toEqual('7700.00')
        ->and($result->liabilitiesTotal)->toEqual('2000.00')
        ->and($result->equityTotal)->toEqual('5000.00')
        ->and($result->currentYearEarnings)->toEqual('700.00')
        ->and($result->totalEquityAndEarnings)->toEqual('5700.00')
        ->and($result->totalLiabilitiesAndEquity)->toEqual('7700.00')
        ->and($result->isBalanced)->toBeTrue();
});

it('no duplica la utilidad del ejercicio si el año ya se cerró: la utilidad acumulada ya está en la cuenta de patrimonio', function () {
    $fx = periodCloseFixture();
    $journalService = app(PostJournalService::class);

    $journalService->post($fx['company'], $fx['add'], new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
    ], 'Venta de contado');

    $journalService->post($fx['company'], $fx['add'], new DateTime('2026-02-05'), new DateTime('2026-02-05'), [
        new JournalLineInput($fx['expense']->id, $fx['company']->local_currency_id, debit: 400, credit: 0),
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: 400),
    ], 'Gasto operativo');

    $closeService = app(\App\Domains\Accounting\Services\PeriodCloseService::class);
    $closeService->close($fx['company'], $fx['jan']);
    $closeService->closeYear($fx['company'], $fx['fiscalYear'], $fx['acc'], $fx['retainedEarnings']);

    $result = app(BalanceSheetService::class)->build($fx['company'], '2026-02-28');

    expect($result->currentYearEarnings)->toEqual('0.00')
        ->and($result->equityTotal)->toEqual('600.00')
        ->and($result->assetsTotal)->toEqual('600.00')
        ->and($result->isBalanced)->toBeTrue();
});

it('un asiento anulado y su reversión no descuadran el balance — netean en cero, no se excluyen', function () {
    // Mismo bug de fondo que LedgerServiceTest: si el original voided se
    // excluyera del cálculo, su espejo (posted, signo contrario) quedaría
    // sin nada que cancelar y el activo dejaría de cuadrar contra pasivo +
    // patrimonio por el monto completo de la reversión.
    $fx = balanceSheetFixture();

    postBs($fx, $fx['cash'], $fx['capital'], '5000');

    $toReverse = app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-20'), new DateTime('2026-01-20'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 800, credit: 0),
            new JournalLineInput($fx['payable']->id, $fx['company']->local_currency_id, debit: 0, credit: 800),
        ],
    );
    app(PostJournalService::class)->reverse($fx['company'], $toReverse, new DateTime('2026-01-21'));

    $result = app(BalanceSheetService::class)->build($fx['company'], '2026-01-31');

    expect($result->assetsTotal)->toEqual('5000.00')
        ->and($result->liabilitiesTotal)->toEqual('0.00')
        ->and($result->isBalanced)->toBeTrue();
});

it('aísla el balance general entre compañías distintas', function () {
    $fxA = balanceSheetFixture();
    postBs($fxA, $fxA['cash'], $fxA['capital'], '1000');

    $fxB = balanceSheetFixture();
    postBs($fxB, $fxB['cash'], $fxB['capital'], '9000');

    $result = app(BalanceSheetService::class)->build($fxA['company'], '2026-01-31');

    expect($result->assetsTotal)->toEqual('1000.00');
});

it('una cuenta mayor (accepts_posting=false) aparece en el reporte sumando el total de sus cuentas hoja, sin duplicar el total de la sección', function () {
    $fx = balanceSheetFixture();

    // "1-01" es padre de "1-01-01-01-001" (la cuenta $cash del fixture) por
    // prefijo de código — nunca recibe asientos directos (accepts_posting
    // false), pero antes de este fix quedaba excluida del reporte de raíz.
    ChartOfAccount::factory()->nonPosting()->create([
        'company_id' => $fx['company']->id,
        'code' => '1-01',
        'description_es' => 'ACTIVO CIRCULANTE',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
    ]);

    postBs($fx, $fx['cash'], $fx['capital'], '5000');

    $result = app(BalanceSheetService::class)->build($fx['company'], '2026-01-31');

    $header = collect($result->assets)->firstWhere('code', '1-01');

    expect($header)->not->toBeNull()
        ->and($header->amount)->toEqual('5000.00')
        ->and($header->isHeader)->toBeTrue()
        ->and($header->depth)->toBe(1)
        // El total de la sección sigue viniendo solo de cuentas hoja — sumar
        // también la línea de la cuenta mayor lo duplicaría.
        ->and($result->assetsTotal)->toEqual('5000.00');
});
