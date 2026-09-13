<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Tax\Models\TaxRate;

it('calcula el reporte de IVA para el rango de fechas indicado', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001', 'tax_classification' => 'sales',
    ]);
    $ivaDevengado = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '2-01-03-01-001', 'tax_classification' => 'iva_devengado',
    ]);

    $fve = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $taxRate = TaxRate::factory()->create();

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    app(PostJournalService::class)->post($company, $fve, now(), now(), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 1130, credit: 0),
        new JournalLineInput($sales->id, $company->local_currency_id, debit: 0, credit: 1000),
        new JournalLineInput(
            $ivaDevengado->id, $company->local_currency_id, debit: 0, credit: 130,
            taxRateId: $taxRate->id, taxableBase: 1000,
        ),
    ]);

    $this->get(route('tax-report.index', [
        'from' => now()->startOfMonth()->format('Y-m-d'),
        'to' => now()->endOfMonth()->format('Y-m-d'),
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tax/Report')
            ->where('summary.iva_devengado.base', '1000.00')
            ->where('summary.iva_devengado.tax', '130.00')
            ->where('summary.neto_a_pagar', '130.00')
        );
});
