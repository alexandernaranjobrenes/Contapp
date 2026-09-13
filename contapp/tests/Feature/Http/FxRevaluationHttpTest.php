<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\FxRevaluationRun;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;

function fxRevalHttpFixture(): array
{
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id, 'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->startOfMonth()->format('Y-m-d'), 'rate' => '500.000000',
    ]);

    $bankUsd = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-01-02-001', 'currency_mode' => 'foreign', 'account_type' => 'asset',
    ]);
    $equity = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
    $fxGain = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '4-02-01-01-001', 'account_type' => 'income']);
    $fxLoss = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '5-02-01-01-001', 'account_type' => 'expense']);
    $adc = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADC']);
    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'), 'end_date' => now()->endOfMonth()->format('Y-m-d'), 'status' => 'open',
    ]);

    app(PostJournalService::class)->post($company, $add, new DateTime(now()->startOfMonth()->format('Y-m-d')), new DateTime(now()->startOfMonth()->format('Y-m-d')), [
        new JournalLineInput($bankUsd->id, $company->foreign_currency_id, debit: 100, credit: 0),
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 0, credit: 50000),
    ]);

    return compact('user', 'company', 'bankUsd', 'equity', 'fxGain', 'fxLoss', 'adc');
}

it('muestra la pantalla de criterios con las cuentas en moneda extranjera de la compañía', function () {
    $fx = fxRevalHttpFixture();

    $this->get(route('fx-revaluation.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('FxRevaluation/Create')
            ->has('accounts', 1)
            ->where('accounts.0.code', $fx['bankUsd']->code)
        );
});

it('calcula el diferencial cambiario vía preview sin contabilizar nada', function () {
    $fx = fxRevalHttpFixture();
    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id, 'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'), 'rate' => '520.000000',
    ]);

    $response = $this->postJson(route('fx-revaluation.preview'), [
        'cutoff_date' => now()->format('Y-m-d'),
    ]);

    $response->assertOk()
        ->assertJsonPath('rows.0.account_code', $fx['bankUsd']->code)
        ->assertJsonPath('rows.0.difference', '2000.00');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(1); // solo el aporte original
});

it('ejecuta el proceso, contabiliza el ajuste y redirige al asiento', function () {
    $fx = fxRevalHttpFixture();
    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id, 'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'), 'rate' => '520.000000',
    ]);

    $response = $this->post(route('fx-revaluation.store'), [
        'cutoff_date' => now()->format('Y-m-d'),
        'document_type_id' => $fx['adc']->id,
        'gain_account_id' => $fx['fxGain']->id,
        'loss_account_id' => $fx['fxLoss']->id,
        'selected_groups' => [
            ['account_id' => $fx['bankUsd']->id, 'business_partner_id' => null],
        ],
    ]);

    $run = FxRevaluationRun::sole();
    $response->assertRedirect(route('journal-entries.show', $run->journal_entry_id));

    $journalEntry = JournalEntry::withoutGlobalScope(CompanyScope::class)->find($run->journal_entry_id);
    $gainLine = $journalEntry->details->firstWhere('account_id', $fx['fxGain']->id);
    expect($gainLine->credit_local)->toEqual('2000.00');
});

it('rechaza ejecutar sin ningún grupo seleccionado', function () {
    $fx = fxRevalHttpFixture();

    $this->post(route('fx-revaluation.store'), [
        'cutoff_date' => now()->format('Y-m-d'),
        'document_type_id' => $fx['adc']->id,
        'gain_account_id' => $fx['fxGain']->id,
        'loss_account_id' => $fx['fxLoss']->id,
        'selected_groups' => [],
    ])->assertSessionHasErrors('selected_groups');

    expect(FxRevaluationRun::count())->toBe(0);
});

it('lista el historial de procesos ya contabilizados', function () {
    $fx = fxRevalHttpFixture();
    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id, 'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'), 'rate' => '520.000000',
    ]);

    $this->post(route('fx-revaluation.store'), [
        'cutoff_date' => now()->format('Y-m-d'),
        'document_type_id' => $fx['adc']->id,
        'gain_account_id' => $fx['fxGain']->id,
        'loss_account_id' => $fx['fxLoss']->id,
        'selected_groups' => [['account_id' => $fx['bankUsd']->id, 'business_partner_id' => null]],
    ]);

    $this->get(route('fx-revaluation.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('FxRevaluation/Index')
            ->has('runs', 1)
            ->where('runs.0.gain_account.code', $fx['fxGain']->code)
        );
});

it('muestra el detalle de un proceso ejecutado', function () {
    $fx = fxRevalHttpFixture();
    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id, 'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'), 'rate' => '520.000000',
    ]);

    $this->post(route('fx-revaluation.store'), [
        'cutoff_date' => now()->format('Y-m-d'),
        'document_type_id' => $fx['adc']->id,
        'gain_account_id' => $fx['fxGain']->id,
        'loss_account_id' => $fx['fxLoss']->id,
        'selected_groups' => [['account_id' => $fx['bankUsd']->id, 'business_partner_id' => null]],
    ]);
    $run = FxRevaluationRun::sole();

    $this->get(route('fx-revaluation.show', $run->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('FxRevaluation/Show')
            ->has('run.details', 1)
            ->where('run.details.0.difference', '2000.00')
        );
});

it('rechaza ver un proceso de otra compañía', function () {
    fxRevalHttpFixture();
    $companyB = Company::factory()->create();
    $adcB = DocumentType::factory()->create(['company_id' => $companyB->id, 'code' => 'ADC']);

    // FxRevaluationRun sí tiene company_id/BelongsToCompany propio (a
    // diferencia de BankReconciliation/AccountReconciliation) — el
    // aislamiento acá lo da el CompanyScope automático, sin chequeo manual
    // en el controlador.
    $runB = FxRevaluationRun::withoutGlobalScope(CompanyScope::class)->create([
        'company_id' => $companyB->id,
        'cutoff_date' => '2026-01-31',
        'exchange_rate_used' => '520.000000',
        'document_type_id' => $adcB->id,
        'status' => 'completed',
    ]);

    $this->get(route('fx-revaluation.show', $runB->id))->assertNotFound();
});
