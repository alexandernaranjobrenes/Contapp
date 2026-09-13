<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * @param  array<int,array<int,mixed>>  $rows
 */
function makeOpeningBalanceXlsx(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ob_import_').'.xlsx';

    $writer = new Writer();
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['cuenta', 'socio', 'moneda', 'debito', 'credito', 'centro_costo', 'descripcion_linea']));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, 'saldos-iniciales.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function openingBalanceHttpFixture(): array
{
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $capital = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
    $cxc = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-02-01-001', 'requires_business_partner' => true,
    ]);
    $client = BusinessPartner::create([
        'company_id' => $company->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id, 'status' => 'active',
    ]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    return compact('user', 'company', 'cash', 'capital', 'cxc', 'client');
}

it('muestra la pantalla de carga de saldos iniciales', function () {
    logInAsCompanyUser();

    $this->get(route('opening-balance.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('OpeningBalance/Create'));
});

it('descarga la plantilla xlsx de saldos iniciales', function () {
    logInAsCompanyUser();

    $response = $this->get(route('opening-balance.template'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
});

it('contabiliza un asiento de apertura balanceado y crea el tipo de documento reservado de oficio', function () {
    $fx = openingBalanceHttpFixture();

    $file = makeOpeningBalanceXlsx([
        [$fx['cash']->code, '', 'CRC', '1000', '0', '', ''],
        [$fx['capital']->code, '', 'CRC', '0', '1000', '', ''],
    ]);

    $response = $this->post(route('opening-balance.import'), [
        'posting_date' => now()->format('Y-m-d'),
        'file' => $file,
    ]);

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $response->assertRedirect(route('journal-entries.show', $entry->id));

    expect($entry->status)->toBe('posted')
        ->and($entry->documentType->code)->toBe('APE')
        ->and($entry->documentType->is_opening_type)->toBeTrue();

    $cashDetail = $entry->details->firstWhere('account_id', $fx['cash']->id);
    expect($cashDetail->debit_local)->toEqual('1000.00');
});

it('reutiliza el mismo tipo de documento reservado en una segunda carga, sin duplicarlo', function () {
    $fx = openingBalanceHttpFixture();

    $this->post(route('opening-balance.import'), [
        'posting_date' => now()->format('Y-m-d'),
        'file' => makeOpeningBalanceXlsx([
            [$fx['cash']->code, '', 'CRC', '100', '0', '', ''],
            [$fx['capital']->code, '', 'CRC', '0', '100', '', ''],
        ]),
    ])->assertSessionHasNoErrors();

    $this->post(route('opening-balance.import'), [
        'posting_date' => now()->format('Y-m-d'),
        'file' => makeOpeningBalanceXlsx([
            [$fx['cash']->code, '', 'CRC', '50', '0', '', ''],
            [$fx['capital']->code, '', 'CRC', '0', '50', '', ''],
        ]),
    ])->assertSessionHasNoErrors();

    expect(DocumentType::where('company_id', $fx['company']->id)->where('code', 'APE')->count())->toBe(1)
        ->and(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $fx['company']->id)->count())->toBe(2);
});

it('una línea con socio de negocio abre partida pendiente al cargar saldos iniciales', function () {
    $fx = openingBalanceHttpFixture();

    $this->post(route('opening-balance.import'), [
        'posting_date' => now()->format('Y-m-d'),
        'file' => makeOpeningBalanceXlsx([
            ['', $fx['client']->code, 'CRC', '750', '0', '', ''],
            [$fx['capital']->code, '', 'CRC', '0', '750', '', ''],
        ]),
    ])->assertSessionHasNoErrors();

    $openItem = BpOpenItem::sole();
    expect($openItem->business_partner_id)->toBe($fx['client']->id)
        ->and($openItem->document_type_code)->toBe('APE')
        ->and($openItem->original_amount)->toEqual('750.00')
        ->and($openItem->balance)->toEqual('750.00')
        ->and($openItem->status)->toBe('open');
});

it('rechaza un archivo de saldos iniciales que no cuadra, sin contabilizar nada', function () {
    $fx = openingBalanceHttpFixture();

    $this->post(route('opening-balance.import'), [
        'posting_date' => now()->format('Y-m-d'),
        'file' => makeOpeningBalanceXlsx([
            [$fx['cash']->code, '', 'CRC', '1000', '0', '', ''],
            [$fx['capital']->code, '', 'CRC', '0', '999', '', ''],
        ]),
    ])->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'no cuadra')));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $fx['company']->id)->count())->toBe(0);
});

it('rechaza una fila con un código de cuenta que no existe en la compañía, sin contabilizar nada', function () {
    $fx = openingBalanceHttpFixture();

    $this->post(route('opening-balance.import'), [
        'posting_date' => now()->format('Y-m-d'),
        'file' => makeOpeningBalanceXlsx([
            ['CUENTA-INEXISTENTE', '', 'CRC', '500', '0', '', ''],
            [$fx['capital']->code, '', 'CRC', '0', '500', '', ''],
        ]),
    ])->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'CUENTA-INEXISTENTE')));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $fx['company']->id)->count())->toBe(0);
});

it('ignora sin error las filas de la plantilla que se dejaron sin saldo', function () {
    $fx = openingBalanceHttpFixture();

    $this->post(route('opening-balance.import'), [
        'posting_date' => now()->format('Y-m-d'),
        'file' => makeOpeningBalanceXlsx([
            [$fx['cash']->code, '', 'CRC', '300', '0', '', ''],
            [$fx['capital']->code, '', 'CRC', '0', '300', '', ''],
            // fila precargada por la plantilla, sin tocar: sin error, se ignora
            [$fx['cxc']->code, '', 'CRC', '0', '0', '', ''],
        ]),
    ])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->details)->toHaveCount(2);
});

it('rechaza importar sin indicar la fecha del asiento de apertura', function () {
    $fx = openingBalanceHttpFixture();

    $this->post(route('opening-balance.import'), [
        'file' => makeOpeningBalanceXlsx([
            [$fx['cash']->code, '', 'CRC', '100', '0', '', ''],
            [$fx['capital']->code, '', 'CRC', '0', '100', '', ''],
        ]),
    ])->assertSessionHasErrors('posting_date');
});

it('el tipo de documento reservado de apertura no aparece en el formulario manual de asientos', function () {
    $fx = openingBalanceHttpFixture();

    $this->post(route('opening-balance.import'), [
        'posting_date' => now()->format('Y-m-d'),
        'file' => makeOpeningBalanceXlsx([
            [$fx['cash']->code, '', 'CRC', '100', '0', '', ''],
            [$fx['capital']->code, '', 'CRC', '0', '100', '', ''],
        ]),
    ])->assertSessionHasNoErrors();

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documentTypes', fn ($types) => ! collect($types)->contains('code', 'APE'))
        );
});
