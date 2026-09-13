<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Services\BankReconciliationReportExporter;
use App\Domains\Banking\Services\BankReconciliationReportService;
use App\Domains\Banking\Services\BankReconciliationService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use OpenSpout\Reader\XLSX\Reader;

/**
 * @return array<int, array<int, mixed>>
 */
function readBankReconciliationReportRows(string $path): array
{
    $reader = new Reader();
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();
    unlink($path);

    return $rows;
}

function bankReconciliationReportFixture(): array
{
    $company = Company::factory()->create();
    app(App\Domains\Core\Support\CurrentCompany::class)->set($company);

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $bankGlAccount = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $equity = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);

    $bankAccount = BankAccount::factory()->create([
        'company_id' => $company->id,
        'gl_account_id' => $bankGlAccount->id,
        'currency_id' => $company->local_currency_id,
    ]);

    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'open',
    ]);

    $service = app(PostJournalService::class);

    $service->post($company, $add, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput(
            $bankGlAccount->id, $company->local_currency_id, debit: 1000, credit: 0, description: 'Depósito ACME',
            referenceDocument: 'Factura-4521', referenceDocumentDate: '2026-01-04',
        ),
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 0, credit: 1000, description: 'Depósito ACME'),
    ]);

    $service->post($company, $add, new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 200, credit: 0, description: 'Cheque #045 girado'),
        new JournalLineInput($bankGlAccount->id, $company->local_currency_id, debit: 0, credit: 200, description: 'Cheque #045 girado'),
    ]);

    return compact('company', 'bankAccount');
}

it('el XLSX incluye el detalle de línea, marcando lo pendiente de confirmar en banco', function () {
    $fx = bankReconciliationReportFixture();

    $reconService = app(BankReconciliationService::class);
    $reconciliation = $reconService->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);
    $depositLine = $reconciliation->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');
    $reconService->confirmInBank($depositLine);

    $rows = app(BankReconciliationReportService::class)->build($fx['bankAccount'], 2026, 1);
    $header = app(ReportHeaderFactory::class)->make(
        $fx['company'], \App\Models\User::factory()->create(['default_company_id' => $fx['company']->id]),
        'Conciliaciones bancarias', 'Prueba',
    );

    $path = sys_get_temp_dir().'/bank-recon-report-'.uniqid().'.xlsx';
    app(BankReconciliationReportExporter::class)->writeTo($path, $header, $fx['bankAccount'], $rows);
    $flat = collect(readBankReconciliationReportRows($path))->flatten()->implode('|');

    expect($flat)->toContain('Detalle de movimientos por conciliación')
        ->and($flat)->toContain('Depósito ACME')
        ->and($flat)->toContain('Cheque #045 girado')
        ->and($flat)->toContain('Depósito')
        ->and($flat)->toContain('Cheque')
        ->and($flat)->toContain('Documento de referencia')->toContain('Fecha de documento')
        ->and($flat)->toContain('Factura-4521')->toContain('2026-01-04');
});

it('el XLSX no revienta cuando el período no tiene conciliaciones', function () {
    $fx = bankReconciliationReportFixture();

    $rows = app(BankReconciliationReportService::class)->build($fx['bankAccount'], 2026, 6);
    $header = app(ReportHeaderFactory::class)->make(
        $fx['company'], \App\Models\User::factory()->create(['default_company_id' => $fx['company']->id]),
        'Conciliaciones bancarias', 'Prueba',
    );

    $path = sys_get_temp_dir().'/bank-recon-report-'.uniqid().'.xlsx';
    app(BankReconciliationReportExporter::class)->writeTo($path, $header, $fx['bankAccount'], $rows);
    $flat = collect(readBankReconciliationReportRows($path))->flatten()->implode('|');

    expect($flat)->toContain('Sin conciliaciones en el período seleccionado.');
});
