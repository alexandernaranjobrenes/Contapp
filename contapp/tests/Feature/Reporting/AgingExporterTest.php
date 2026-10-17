<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\AgingExporter;
use App\Domains\BusinessPartners\Services\AgingService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use App\Models\User;
use OpenSpout\Reader\XLSX\Reader;

/**
 * @return array<int, array<int, mixed>>
 */
function readAgingExportRows(string $path): array
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

function agingExporterFixture(): array
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
        $company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'),
        [
            new JournalLineInput(
                $cxc->id, $client->currency_id, debit: '200', credit: 0,
                businessPartnerId: $client->id, dueDate: '2026-02-15', opensItem: true,
            ),
            new JournalLineInput($sales->id, $client->currency_id, debit: 0, credit: '200'),
        ],
    );

    return compact('company', 'client');
}

it('sin include_documents, el xlsx no trae el detalle por documento', function () {
    $fx = agingExporterFixture();
    $result = app(AgingService::class)->build($fx['company'], '2026-03-01');
    $header = app(ReportHeaderFactory::class)->make($fx['company'], User::factory()->create(), 'Antigüedad de saldos', 'Al 2026-03-01');

    $path = sys_get_temp_dir().'/aging-'.uniqid().'.xlsx';
    app(AgingExporter::class)->writeTo($path, $header, $result);
    $flat = collect(readAgingExportRows($path))->flatten()->implode('|');

    expect($flat)->toContain($fx['client']->code)
        ->and($flat)->not->toContain('Monto original');
});

it('con include_documents, el xlsx trae el detalle por documento debajo de cada socio', function () {
    $fx = agingExporterFixture();
    $result = app(AgingService::class)->build($fx['company'], '2026-03-01');
    $header = app(ReportHeaderFactory::class)->make($fx['company'], User::factory()->create(), 'Antigüedad de saldos', 'Al 2026-03-01');

    $path = sys_get_temp_dir().'/aging-'.uniqid().'.xlsx';
    app(AgingExporter::class)->writeTo($path, $header, $result, true);
    $flat = collect(readAgingExportRows($path))->flatten()->implode('|');

    expect($flat)->toContain('Monto original')->toContain('Saldo pendiente')
        ->and($flat)->toContain('2026-02-15')
        ->and($flat)->toContain('200');
});
