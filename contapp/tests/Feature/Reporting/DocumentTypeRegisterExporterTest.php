<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Services\DocumentTypeRegisterExporter;
use OpenSpout\Reader\XLSX\Reader;

/**
 * @return array<int, array<int, mixed>>
 */
function readFirstSheetRows(string $path): array
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

function registerFixture(): array
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
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $documentType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $otherType = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('company', 'cash', 'sales', 'documentType', 'otherType');
}

it('exporta una fila por línea de detalle, solo del tipo de documento elegido', function () {
    $fx = registerFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-10'), new DateTime('2026-01-10'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '500', credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '500'),
        ],
        'Venta de contado'
    );

    // Asiento de OTRO tipo de documento — no debe aparecer en el registro de FVE.
    app(PostJournalService::class)->post(
        $fx['company'], $fx['otherType'], new DateTime('2026-01-11'), new DateTime('2026-01-11'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '100', credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '100'),
        ],
    );

    $path = sys_get_temp_dir().'/doctype-register-'.uniqid().'.xlsx';
    app(DocumentTypeRegisterExporter::class)->writeTo($path, $fx['documentType'], null, null, ['posted']);

    $flat = collect(readFirstSheetRows($path))->flatten()->implode('|');

    expect($flat)->toContain('500')
        ->and($flat)->not->toContain('100');
});

it('con document type null exporta TODOS los tipos de documento, con una columna que distingue cada uno', function () {
    $fx = registerFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-10'), new DateTime('2026-01-10'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '500', credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '500'),
        ],
    );
    app(PostJournalService::class)->post(
        $fx['company'], $fx['otherType'], new DateTime('2026-01-11'), new DateTime('2026-01-11'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '100', credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '100'),
        ],
    );

    $path = sys_get_temp_dir().'/doctype-register-'.uniqid().'.xlsx';
    app(DocumentTypeRegisterExporter::class)->writeTo($path, null, null, null, ['posted']);
    $flat = collect(readFirstSheetRows($path))->flatten()->implode('|');

    expect($flat)->toContain('Todos los tipos de documento')
        ->and($flat)->toContain('Tipo de documento')
        ->and($flat)->toContain('500')->toContain('100')
        ->and($flat)->toContain($fx['documentType']->code)->toContain($fx['otherType']->code);
});

it('respeta el filtro de estado: un borrador no aparece si solo se pide "posted"', function () {
    $fx = registerFixture();

    // saveDraft deja el asiento en 'draft', nunca contabilizado.
    app(PostJournalService::class)->saveDraft(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-10'), new DateTime('2026-01-10'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '777', credit: 0, allowZeroAmount: true),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '777', allowZeroAmount: true),
        ],
        'Borrador sin contabilizar'
    );

    $postedOnly = sys_get_temp_dir().'/doctype-register-'.uniqid().'.xlsx';
    app(DocumentTypeRegisterExporter::class)->writeTo($postedOnly, $fx['documentType'], null, null, ['posted']);
    $postedFlat = collect(readFirstSheetRows($postedOnly))->flatten()->implode('|');

    $withDrafts = sys_get_temp_dir().'/doctype-register-'.uniqid().'.xlsx';
    app(DocumentTypeRegisterExporter::class)->writeTo($withDrafts, $fx['documentType'], null, null, ['draft', 'posted']);
    $withDraftsFlat = collect(readFirstSheetRows($withDrafts))->flatten()->implode('|');

    expect($postedFlat)->not->toContain('777')
        ->and($withDraftsFlat)->toContain('777')
        ->and($withDraftsFlat)->toContain('Preliminar');
});

it('respeta el rango de fechas indicado', function () {
    $fx = registerFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-05'), new DateTime('2026-01-05'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '111', credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '111'),
        ],
    );
    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-20'), new DateTime('2026-01-20'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '222', credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '222'),
        ],
    );

    $path = sys_get_temp_dir().'/doctype-register-'.uniqid().'.xlsx';
    app(DocumentTypeRegisterExporter::class)->writeTo($path, $fx['documentType'], '2026-01-15', '2026-01-31', ['posted']);
    $flat = collect(readFirstSheetRows($path))->flatten()->implode('|');

    expect($flat)->toContain('222')
        ->and($flat)->not->toContain('111');
});

it('incluye el documento de referencia y su fecha por línea, distintos dentro del mismo asiento', function () {
    $fx = registerFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['documentType'], new DateTime('2026-01-10'), new DateTime('2026-01-10'),
        [
            new JournalLineInput(
                $fx['cash']->id, $fx['company']->local_currency_id, debit: '500', credit: 0,
                referenceDocument: 'Factura-4521', referenceDocumentDate: '2026-01-08',
            ),
            new JournalLineInput(
                $fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '500',
                referenceDocument: 'Factura-4522', referenceDocumentDate: '2026-01-09',
            ),
        ],
        'Venta de contado'
    );

    $path = sys_get_temp_dir().'/doctype-register-'.uniqid().'.xlsx';
    app(DocumentTypeRegisterExporter::class)->writeTo($path, $fx['documentType'], null, null, ['posted']);
    $flat = collect(readFirstSheetRows($path))->flatten()->implode('|');

    expect($flat)->toContain('Documento de referencia')->toContain('Fecha de documento')
        ->and($flat)->toContain('Factura-4521')->toContain('2026-01-08')
        ->and($flat)->toContain('Factura-4522')->toContain('2026-01-09');
});
