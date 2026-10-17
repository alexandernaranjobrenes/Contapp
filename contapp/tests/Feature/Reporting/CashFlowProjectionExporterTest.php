<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\CashFlowProjectionExporter;
use App\Domains\BusinessPartners\Services\CashFlowProjectionService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use App\Models\User;
use OpenSpout\Reader\XLSX\Reader;

/**
 * @return array<int, array<int, mixed>>
 */
function readCashFlowExportRows(string $path): array
{
    $reader = new Reader;
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

function cashFlowExporterFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001']);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $client = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-01-01'), new DateTime('2026-01-01'),
        [
            new JournalLineInput(
                $cxc->id, $client->currency_id, debit: '100', credit: 0,
                businessPartnerId: $client->id, dueDate: '2026-01-10', opensItem: true,
            ),
            new JournalLineInput($sales->id, $client->currency_id, debit: 0, credit: '100'),
        ],
    );

    return compact('company', 'client');
}

it('sin include_documents, el xlsx no trae el detalle por documento', function () {
    $fx = cashFlowExporterFixture();
    $result = app(CashFlowProjectionService::class)->build($fx['company'], '2026-01-01');
    $header = app(ReportHeaderFactory::class)->make($fx['company'], User::factory()->create(), 'Proyección de cobros y pagos', 'Al 2026-01-01');

    $path = sys_get_temp_dir().'/cashflow-'.uniqid().'.xlsx';
    app(CashFlowProjectionExporter::class)->writeTo($path, $header, $result);
    $flat = collect(readCashFlowExportRows($path))->flatten()->implode('|');

    expect($flat)->toContain($fx['client']->code)
        ->and($flat)->not->toContain('Monto original');
});

it('con include_documents, el xlsx trae el detalle por documento debajo de cada socio', function () {
    $fx = cashFlowExporterFixture();
    $result = app(CashFlowProjectionService::class)->build($fx['company'], '2026-01-01');
    $header = app(ReportHeaderFactory::class)->make($fx['company'], User::factory()->create(), 'Proyección de cobros y pagos', 'Al 2026-01-01');

    $path = sys_get_temp_dir().'/cashflow-'.uniqid().'.xlsx';
    app(CashFlowProjectionExporter::class)->writeTo($path, $header, $result, true);
    $flat = collect(readCashFlowExportRows($path))->flatten()->implode('|');

    expect($flat)->toContain('Monto original')->toContain('Saldo pendiente')
        ->and($flat)->toContain('2026-01-10')
        ->and($flat)->toContain('100');
});
