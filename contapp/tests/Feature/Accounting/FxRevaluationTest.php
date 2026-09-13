<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\FxRevaluationService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\ApplyPaymentService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;

function fxRevalFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $bankUsd = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-01-02-001', 'currency_mode' => 'foreign',
    ]);
    $equity = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
    $fxGainLoss = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-02-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);

    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);
    $adc = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADC']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    app(PostJournalService::class)->post($company, $add, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($bankUsd->id, $company->foreign_currency_id, debit: 100, credit: 0),
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 0, credit: 52000),
    ], 'Aporte en dólares');

    return compact('company', 'bankUsd', 'equity', 'fxGainLoss', 'adc');
}

it('genera el asiento de diferencial cambiario sin alterar el saldo en moneda extranjera', function () {
    $fx = fxRevalFixture();

    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id,
        'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => '2026-01-31',
        'rate' => '530.000000',
    ]);

    $fcBalanceBefore = JournalDetail::where('account_id', $fx['bankUsd']->id)
        ->selectRaw('SUM(debit_foreign - credit_foreign) as bal')->first()->bal;

    $run = app(FxRevaluationService::class)->execute(
        $fx['company'], $fx['adc'], new DateTime('2026-01-31'), $fx['fxGainLoss'], $fx['fxGainLoss'],
        [['account_id' => $fx['bankUsd']->id, 'business_partner_id' => null]],
    );

    expect((string) $run->exchange_rate_used)->toEqual('530.000000')
        ->and($run->details)->toHaveCount(1);

    $detail = $run->details->first();
    expect($detail->foreign_balance)->toEqual('100.00')
        ->and($detail->historical_local_amount)->toEqual('52000.00')
        ->and($detail->revalued_local_amount)->toEqual('53000.00')
        ->and($detail->difference)->toEqual('1000.00');

    $journalEntry = JournalEntry::withoutGlobalScope(CompanyScope::class)->with('details')->find($run->journal_entry_id);
    expect($journalEntry->details)->toHaveCount(2);

    $fcBalanceAfter = JournalDetail::where('account_id', $fx['bankUsd']->id)
        ->selectRaw('SUM(debit_foreign - credit_foreign) as bal')->first()->bal;

    expect((string) $fcBalanceAfter)->toEqual((string) $fcBalanceBefore);
});

it('no genera ningún asiento si no hay diferencia cambiaria', function () {
    $fx = fxRevalFixture();

    // Mismo tipo de cambio de cierre que el de registro: diferencia cero.
    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id,
        'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => '2026-01-31',
        'rate' => '520.000000',
    ]);

    $run = app(FxRevaluationService::class)->execute(
        $fx['company'], $fx['adc'], new DateTime('2026-01-31'), $fx['fxGainLoss'], $fx['fxGainLoss'],
        [['account_id' => $fx['bankUsd']->id, 'business_partner_id' => null]],
    );

    expect($run->journal_entry_id)->toBeNull()
        ->and($run->details)->toHaveCount(0);
});

it('rechaza ejecutar sin ningún grupo seleccionado', function () {
    $fx = fxRevalFixture();

    app(FxRevaluationService::class)->execute(
        $fx['company'], $fx['adc'], new DateTime('2026-01-31'), $fx['fxGainLoss'], $fx['fxGainLoss'], []
    );
})->throws(InvalidArgumentException::class);

it('preview() no contabiliza nada, solo calcula', function () {
    $fx = fxRevalFixture();
    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id, 'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => '2026-01-31', 'rate' => '530.000000',
    ]);

    $rows = app(FxRevaluationService::class)->preview($fx['company'], new DateTime('2026-01-31'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['account_id'])->toBe($fx['bankUsd']->id)
        ->and($rows[0]['difference'])->toEqual('1000.00')
        ->and(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(1); // solo el aporte original del fixture
});

it('preview() respeta el filtro de "solo cuentas" excluyendo grupos con socio de negocio', function () {
    $fx = fxRevalFixture();
    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id, 'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => '2026-01-31', 'rate' => '530.000000',
    ]);

    $cxc = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-02-01-001', 'currency_mode' => 'foreign',
    ]);
    $client = BusinessPartner::factory()->create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->foreign_currency_id,
    ]);
    app(PostJournalService::class)->post($fx['company'], $fx['adc'], new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($cxc->id, $fx['company']->foreign_currency_id, debit: 50, credit: 0, businessPartnerId: $client->id),
        new JournalLineInput($fx['equity']->id, $fx['company']->local_currency_id, debit: 0, credit: 26000),
    ]);

    $onlyAccounts = app(FxRevaluationService::class)->preview(
        $fx['company'], new DateTime('2026-01-31'), includeAccounts: true, includeBusinessPartners: false,
    );
    expect($onlyAccounts)->toHaveCount(1)
        ->and($onlyAccounts[0]['business_partner_id'])->toBeNull();

    $onlyPartners = app(FxRevaluationService::class)->preview(
        $fx['company'], new DateTime('2026-01-31'), includeAccounts: false, includeBusinessPartners: true,
    );
    expect($onlyPartners)->toHaveCount(1)
        ->and($onlyPartners[0]['business_partner_id'])->toBe($client->id);
});

it('separa la ganancia y la pérdida en cuentas distintas, sin netear entre grupos', function () {
    $company = Company::factory()->create();
    ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01', 'rate' => '500.000000',
    ]);

    $gains = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-02-001', 'currency_mode' => 'foreign']);
    $losses = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-02-002', 'currency_mode' => 'foreign']);
    $equity = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
    $fxGain = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '4-02-01-01-001', 'account_type' => 'income']);
    $fxLoss = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '5-02-01-01-001', 'account_type' => 'expense']);
    $adc = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADC']);
    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    // $gains sube de valor al cierre (posición activa en USD que gana);
    // $losses también es un activo en USD, pero el saldo se arma negativo
    // (más créditos que débitos) para que el cierre le genere pérdida.
    app(PostJournalService::class)->post($company, $add, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($gains->id, $company->foreign_currency_id, debit: 100, credit: 0),
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 0, credit: 50000),
    ]);
    app(PostJournalService::class)->post($company, $add, new DateTime('2026-01-06'), new DateTime('2026-01-06'), [
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 50000, credit: 0),
        new JournalLineInput($losses->id, $company->foreign_currency_id, debit: 0, credit: 100),
    ]);

    ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-31', 'rate' => '520.000000',
    ]);

    $run = app(FxRevaluationService::class)->execute(
        $company, $adc, new DateTime('2026-01-31'), $fxGain, $fxLoss,
        [
            ['account_id' => $gains->id, 'business_partner_id' => null],
            ['account_id' => $losses->id, 'business_partner_id' => null],
        ],
    );

    $journalEntry = JournalEntry::withoutGlobalScope(CompanyScope::class)->with('details')->find($run->journal_entry_id);
    expect($journalEntry->details)->toHaveCount(4); // 2 grupos x (línea propia + contrapartida), sin netear

    $gainCounter = $journalEntry->details->firstWhere('account_id', $fxGain->id);
    $lossCounter = $journalEntry->details->firstWhere('account_id', $fxLoss->id);

    expect($gainCounter->credit_local)->toEqual('2000.00') // 100 * (520-500)
        ->and($lossCounter->debit_local)->toEqual('2000.00'); // -100 * (520-500), en valor absoluto
});

it('la partida abierta guarda el tipo de cambio de la revaluación, para no contar dos veces el diferencial ya reconocido', function () {
    $company = Company::factory()->create();
    ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01', 'rate' => '500.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001', 'currency_mode' => 'foreign']);
    $sales = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '4-01-01-01-001']);
    $fxGain = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '4-02-01-01-001', 'account_type' => 'income']);
    $fxLoss = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '5-02-01-01-001', 'account_type' => 'expense']);

    $client = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->foreign_currency_id,
    ]);

    $fve = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $trb = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB']);
    $adc = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADC']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 2,
        'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'open',
    ]);

    // Factura de $100 al 2026-01-05, TC 500 -> partida abierta de $100.
    app(PostJournalService::class)->post($company, $fve, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($cxc->id, $company->foreign_currency_id, debit: 100, credit: 0, businessPartnerId: $client->id, opensItem: true),
        new JournalLineInput($sales->id, $company->foreign_currency_id, debit: 0, credit: 100),
    ]);
    $openItem = BpOpenItem::sole();

    // Revaluación de cierre al 2026-01-31, TC 520: reconoce 100*(520-500)=2000 de ganancia NO realizada.
    ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-31', 'rate' => '520.000000',
    ]);
    app(FxRevaluationService::class)->execute(
        $company, $adc, new DateTime('2026-01-31'), $fxGain, $fxLoss,
        [['account_id' => $cxc->id, 'business_partner_id' => $client->id]],
    );

    expect((string) $openItem->fresh()->last_revaluation_rate)->toEqual('520.000000');

    // Cobro al 2026-02-15, TC 530: el diferencial REALIZADO debe partir de
    // 520 (la última revaluación), no de 500 (el original) — si no, se
    // contaría dos veces la porción 500->520 ya reconocida arriba.
    ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-02-15', 'rate' => '530.000000',
    ]);
    $payment = app(PostJournalService::class)->post($company, $trb, new DateTime('2026-02-15'), new DateTime('2026-02-15'), [
        new JournalLineInput($cash->id, $company->foreign_currency_id, debit: 100, credit: 0),
        new JournalLineInput($cxc->id, $company->foreign_currency_id, debit: 0, credit: 100),
    ]);

    $application = app(ApplyPaymentService::class)->apply(
        $openItem->fresh(), $payment, 100, new DateTime('2026-02-15'), exchangeRate: '530.000000'
    );

    // (530-520)*100 = 1000 — NO (530-500)*100 = 3000.
    expect($application->realized_fx_difference)->toEqual('1000.00');
});
