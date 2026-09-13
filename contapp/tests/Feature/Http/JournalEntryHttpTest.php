<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalEntryTemplateExporter;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\DocumentTypeNumberSeries;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Tax\Models\JournalDetailTax;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * @param  array<int,array<int,mixed>>  $rows
 */
function makeJournalEntryXlsx(array $rows, ?array $headers = null): UploadedFile
{
    $headers ??= JournalEntryTemplateExporter::HEADERS;

    $path = tempnam(sys_get_temp_dir(), 'je_import_').'.xlsx';

    $writer = new Writer();
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, 'asiento.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function journalHttpFixture(): array
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
    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    return compact('user', 'company', 'cash', 'capital', 'add');
}

it('muestra el formulario de creación con los datos de la compañía activa', function () {
    journalHttpFixture();

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->has('documentTypes', 1)
            ->has('accounts', 2)
        );
});

it('contabiliza un asiento balanceado enviado desde el formulario', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Aporte de capital',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(1);
});

it('rechaza un asiento que no cuadra y vuelve al formulario con el error del service', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('lines');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('el formulario de creación también trae las normas de reparto activas y vigentes de la compañía', function () {
    $fx = journalHttpFixture();
    $cc = CostCenter::factory()->create(['company_id' => $fx['company']->id, 'code' => 'CC-ADM']);
    CostAllocationRule::factory()->withEvenSplit($cc)->create(['company_id' => $fx['company']->id, 'code' => 'NORMA-OK', 'is_active' => true]);
    CostAllocationRule::factory()->withEvenSplit($cc)->create(['company_id' => $fx['company']->id, 'code' => 'NORMA-OLD', 'is_active' => false]);

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->has('costAllocationRules', 1)
            ->where('costAllocationRules.0.code', 'NORMA-OK')
        );
});

it('guarda la norma de reparto de una línea cuando se envía desde el formulario, explotada en una fila por centro de costo', function () {
    $fx = journalHttpFixture();
    $ccA = CostCenter::factory()->create(['company_id' => $fx['company']->id, 'code' => 'CC-A']);
    $ccB = CostCenter::factory()->create(['company_id' => $fx['company']->id, 'code' => 'CC-B']);
    $rule = CostAllocationRule::factory()->create(['company_id' => $fx['company']->id, 'code' => 'NORMA1']);
    $rule->lines()->create(['cost_center_id' => $ccA->id, 'percentage' => '60.00', 'position' => 1]);
    $rule->lines()->create(['cost_center_id' => $ccB->id, 'percentage' => '40.00', 'position' => 2]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0, 'cost_allocation_rule_id' => $rule->id],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $cashDetails = $entry->details->where('account_id', $fx['cash']->id);

    expect($cashDetails)->toHaveCount(2)
        ->and($cashDetails->firstWhere('cost_center_id', $ccA->id)->debit_local)->toEqual('300.00')
        ->and($cashDetails->firstWhere('cost_center_id', $ccB->id)->debit_local)->toEqual('200.00');
});

it('rechaza un asiento contra una cuenta que exige centro de costo si no se envía ninguno', function () {
    $fx = journalHttpFixture();
    $gasto = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '6-01-01-01-001', 'requires_cost_center' => true,
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $gasto->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('lines');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('el formulario de creación trae los socios de negocio activos con su cuenta contable', function () {
    $fx = journalHttpFixture();
    $active = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente activo', 'type' => 'client',
        'gl_account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);
    BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-002', 'name' => 'Cliente inactivo', 'type' => 'client',
        'gl_account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'inactive',
    ]);

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->has('businessPartners', 1)
            ->where('businessPartners.0.code', $active->code)
            ->where('businessPartners.0.gl_account_id', $fx['cash']->id)
        );
});

it('guarda el socio de negocio de una línea cuando se envía desde el formulario', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-02-01-001', 'requires_business_partner' => true,
    ]);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 300, 'credit' => 0, 'business_partner_id' => $partner->id],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 300],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $cxcDetail = $entry->details->firstWhere('account_id', $cxc->id);

    expect($cxcDetail->business_partner_id)->toBe($partner->id);
});

it('rechaza un socio de negocio de otra compañía en una línea de asiento', function () {
    $fx = journalHttpFixture();
    $companyB = Company::factory()->create();
    $glB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $partnerB = BusinessPartner::create([
        'company_id' => $companyB->id, 'code' => 'X-001', 'name' => 'Ajeno', 'type' => 'client',
        'gl_account_id' => $glB->id, 'currency_id' => $companyB->local_currency_id, 'status' => 'active',
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'business_partner_id' => $partnerB->id],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('lines.0.business_partner_id');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

// --- Protocolo de control de socio de negocio (aplica/vencimiento) --------
// Ver DocumentType::BP_LINE_REQUIREMENTS y PostJournalService::post().

it('el formulario de creación trae las partidas abiertas de la compañía activa', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0, 'business_partner_id' => $partner->id, 'opens_item' => true],
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $openItem = \App\Domains\BusinessPartners\Models\BpOpenItem::sole();

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->has('openItems', 1)
            ->where('openItems.0.id', $openItem->id)
            ->where('openItems.0.balance', '500.00')
        );
});

it('el formulario de creación trae si cada tipo de documento exige vencimiento o aplicación en sus líneas con socio', function () {
    $fx = journalHttpFixture();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->where('documentTypes', fn ($types) => collect($types)->firstWhere('id', $fx['add']->id)['bp_line_requirement'] === 'none'
                && collect($types)->firstWhere('id', $trb->id)['bp_line_requirement'] === 'application')
        );
});

it('rechaza contabilizar una línea con socio sin aplicación cuando el tipo de documento lo exige', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $trb->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0, 'business_partner_id' => $partner->id],
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ])->assertSessionHasErrors('lines');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('contabiliza una línea que aplica a una partida existente y cierra su saldo, desde el formulario', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    // Primero, una factura que abre la partida (tipo ADD del fixture, sin
    // protocolo exigido — bp_line_requirement en 'none' por defecto).
    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0, 'business_partner_id' => $partner->id, 'opens_item' => true],
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $openItem = \App\Domains\BusinessPartners\Models\BpOpenItem::sole();
    $trb = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'TRB', 'bp_line_requirement' => 'application']);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $trb->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            [
                'account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500,
                'business_partner_id' => $partner->id, 'apply_to_open_item_id' => $openItem->id,
            ],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    expect($openItem->fresh()->balance)->toEqual('0.00')
        ->and($openItem->fresh()->status)->toBe('closed');
});

it('el formulario de creación trae las series activas y no agotadas de cada tipo de documento', function () {
    $fx = journalHttpFixture();
    $activa = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $fx['company']->id, 'document_type_id' => $fx['add']->id,
        'name' => 'Serie A', 'range_from' => 1, 'range_to' => 100, 'next_number' => 5,
    ]);
    DocumentTypeNumberSeries::factory()->create([
        'company_id' => $fx['company']->id, 'document_type_id' => $fx['add']->id,
        'name' => 'Agotada', 'range_from' => 1, 'range_to' => 10, 'next_number' => 11,
    ]);
    DocumentTypeNumberSeries::factory()->create([
        'company_id' => $fx['company']->id, 'document_type_id' => $fx['add']->id,
        'name' => 'Inactiva', 'is_active' => false,
    ]);

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->has('documentTypes.0.number_series', 1)
            ->where('documentTypes.0.number_series.0.id', $activa->id)
        );
});

it('el formulario de creación trae el encargado de cada serie, para identificar quién cobra', function () {
    $fx = journalHttpFixture();
    DocumentTypeNumberSeries::factory()->create([
        'company_id' => $fx['company']->id, 'document_type_id' => $fx['add']->id,
        'name' => 'PJ01', 'holder_name' => 'Pedro Jiménez', 'range_from' => 2000, 'range_to' => 2050,
    ]);

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documentTypes.0.number_series.0.holder_name', 'Pedro Jiménez')
        );
});

it('guarda el número de serie manual de un asiento cuando se elige una', function () {
    $fx = journalHttpFixture();
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $fx['company']->id, 'document_type_id' => $fx['add']->id,
        'range_from' => 1, 'range_to' => 100, 'next_number' => 1,
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'number_series_id' => $series->id,
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->number_series_id)->toBe($series->id)
        ->and($entry->series_number)->toBe(1);
});

it('rechaza una serie de otra compañía al contabilizar', function () {
    $fx = journalHttpFixture();
    $companyB = Company::factory()->create();
    $dtB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    $seriesB = DocumentTypeNumberSeries::factory()->create(['company_id' => $companyB->id, 'document_type_id' => $dtB->id]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'number_series_id' => $seriesB->id,
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('number_series_id');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza contabilizar contra una serie agotada y reporta el error del service', function () {
    $fx = journalHttpFixture();
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $fx['company']->id, 'document_type_id' => $fx['add']->id,
        'range_from' => 1, 'range_to' => 1, 'next_number' => 2,
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'number_series_id' => $series->id,
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('lines');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

// --- Borradores ("guardar como preliminar") -------------------------------

it('guarda un asiento como preliminar con intent=draft, sin exigir que cuadre', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'intent' => 'draft',
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Falta terminar',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    expect($entry->status)->toBe('draft')
        ->and($entry->document_number)->toBeNull();
});

it('muestra el formulario de edición de un borrador existente con sus líneas', function () {
    $fx = journalHttpFixture();
    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'],
        $fx['add'],
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
        'Un borrador',
    );

    $this->get(route('journal-entries.edit', $draft->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->where('entry.id', $draft->id)
            ->has('entry.lines', 1)
        );
});

it('el formulario de edición de un borrador también trae la navegación entre documentos', function () {
    $fx = journalHttpFixture();
    $service = app(\App\Domains\Accounting\Services\PostJournalService::class);

    $older = $service->saveDraft(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );
    $middle = $service->saveDraft(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );
    $newer = $service->saveDraft(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );

    $this->get(route('journal-entries.edit', $middle->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('nav.prev', $older->id)
            ->where('nav.next', $newer->id)
            ->where('nav.first', $older->id)
            ->where('nav.last', $newer->id)
        );
});

it('el formulario de edición devuelve 404 para un asiento ya contabilizado', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $posted = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->get(route('journal-entries.edit', $posted->id))->assertNotFound();
});

it('el formulario de edición devuelve 404 para un borrador de otra compañía', function () {
    journalHttpFixture();
    $companyB = Company::factory()->create();
    $accountB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    $draftB = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $companyB,
        $typeB,
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($accountB->id, $companyB->local_currency_id, debit: 100, credit: 0)],
    );

    $this->get(route('journal-entries.edit', $draftB->id))->assertNotFound();
});

it('actualiza un borrador existente vía intent=draft, reemplazando sus líneas', function () {
    $fx = journalHttpFixture();
    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'],
        $fx['add'],
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );

    $this->put(route('journal-entries.update', $draft->id), [
        'intent' => 'draft',
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Ya completo',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 200, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 200],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $fresh = JournalEntry::withoutGlobalScope(CompanyScope::class)->find($draft->id);
    expect($fresh->status)->toBe('draft')
        ->and($fresh->description)->toBe('Ya completo')
        ->and($fresh->details)->toHaveCount(2);
});

it('contabiliza formalmente un borrador vía intent=post en update', function () {
    $fx = journalHttpFixture();
    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'],
        $fx['add'],
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );

    $this->put(route('journal-entries.update', $draft->id), [
        'intent' => 'post',
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 300, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 300],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $fresh = JournalEntry::withoutGlobalScope(CompanyScope::class)->find($draft->id);
    expect($fresh->status)->toBe('posted')
        ->and($fresh->document_number)->not->toBeNull();
});

it('elimina un borrador', function () {
    $fx = journalHttpFixture();
    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'],
        $fx['add'],
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );

    $this->delete(route('journal-entries.destroy', $draft->id))
        ->assertRedirect(route('journal-entries.index'));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->find($draft->id))->toBeNull();
});

it('rechaza eliminar un asiento ya contabilizado', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $posted = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->delete(route('journal-entries.destroy', $posted->id))->assertNotFound();

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->find($posted->id))->not->toBeNull();
});

it('rechaza eliminar un borrador de otra compañía', function () {
    journalHttpFixture();
    $companyB = Company::factory()->create();
    $accountB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    $draftB = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $companyB,
        $typeB,
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($accountB->id, $companyB->local_currency_id, debit: 100, credit: 0)],
    );

    $this->delete(route('journal-entries.destroy', $draftB->id))->assertNotFound();

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->find($draftB->id))->not->toBeNull();
});

// --- Tipo de cambio: ver el automático, modificarlo, y ver el aplicado ----

it('el formulario de creación trae las monedas de la compañía y el histórico reciente de tipo de cambio', function () {
    $fx = journalHttpFixture();

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->where('company.local_currency.id', $fx['company']->local_currency_id)
            ->where('company.foreign_currency.id', $fx['company']->foreign_currency_id)
            ->where('company.system_currency.id', $fx['company']->system_currency_id)
            ->has('exchangeRates', 1)
            ->where('exchangeRates.0.rate', '520.000000')
        );
});

it('guarda un asiento con un tipo de cambio manual, que gana sobre el automático', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'exchange_rate' => '500.00',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $cashDetail = $entry->details->firstWhere('account_id', $fx['cash']->id);

    expect($cashDetail->exchange_rate_lc_fc)->toEqual('500.000000')
        ->and($cashDetail->debit_foreign)->toEqual('1.00');
});

it('sin tipo de cambio manual, el asiento contabilizado usa el automático de esa fecha', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 520, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 520],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $cashDetail = $entry->details->firstWhere('account_id', $fx['cash']->id);

    expect($cashDetail->exchange_rate_lc_fc)->toEqual('520.000000');
});

it('rechaza un tipo de cambio manual inválido (cero) y no contabiliza nada', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'exchange_rate' => '0',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ])->assertSessionHasErrors('exchange_rate');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('muestra el detalle de un asiento contabilizado, con el tipo de cambio aplicado', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Aporte de capital',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 520, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 520],
        ],
    ]);
    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->get(route('journal-entries.show', $entry->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Show')
            ->where('entry.status', 'posted')
            ->where('entry.exchange_rate_lc_fc', '520.000000')
            ->has('entry.lines', 2)
            // El botón "Exportar registro" (DocumentTypeRegisterPanel) necesita
            // el mismo universo de tipos que Reportes > Registro por tipo de
            // documento, precargado en esta misma página.
            ->where('documentTypes', fn ($types) => collect($types)->pluck('code')->contains($fx['add']->code))
        );
});

it('muestra el detalle de un borrador, todavía sin tipo de cambio resuelto', function () {
    $fx = journalHttpFixture();
    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'],
        $fx['add'],
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );

    $this->get(route('journal-entries.show', $draft->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Show')
            ->where('entry.status', 'draft')
            ->where('entry.exchange_rate_lc_fc', null)
        );
});

it('rechaza ver el detalle de un asiento de otra compañía', function () {
    journalHttpFixture();
    $companyB = Company::factory()->create();
    $accountB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    $draftB = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $companyB,
        $typeB,
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($accountB->id, $companyB->local_currency_id, debit: 100, credit: 0)],
    );

    $this->get(route('journal-entries.show', $draftB->id))->assertNotFound();
});

// --- Importar un asiento completo desde xlsx (queda como preliminar) -----

it('descarga la plantilla xlsx para importar un asiento', function () {
    journalHttpFixture();

    $response = $this->get(route('journal-entries.template'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
});

it('importa un asiento completo desde xlsx y queda como preliminar', function () {
    $fx = journalHttpFixture();

    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), 'Aporte de capital', $fx['cash']->code, '', 'CRC', '500', '0', '', ''],
        ['', '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', ''],
    ]);

    $response = $this->post(route('journal-entries.import'), ['file' => $file]);

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $response->assertRedirect(route('journal-entries.edit', $entry->id));

    expect($entry->status)->toBe('draft')
        ->and($entry->document_number)->toBeNull()
        ->and($entry->description)->toBe('Aporte de capital')
        ->and($entry->details)->toHaveCount(2);

    $cashDetail = $entry->details->firstWhere('account_id', $fx['cash']->id);
    expect($cashDetail->debit_local)->toEqual('500.00');
});

it('importa un asiento cuando la columna "fecha" trae una fecha real de Excel, no solo texto', function () {
    // Si el usuario escribe la fecha en Excel como fecha (no como texto),
    // la celda queda con un formato numérico de fecha — OpenSpout la
    // entrega como DateTimeImmutable, no como string, al leerla de vuelta
    // (reproducido acá dándole a la celda un Style con formato de fecha,
    // igual que hace Excel de verdad al escribir una fecha).
    $fx = journalHttpFixture();
    $fecha = new DateTimeImmutable(now()->format('Y-m-d'));
    $dateStyle = (new \OpenSpout\Common\Entity\Style\Style())->setFormat('yyyy-mm-dd');

    $path = tempnam(sys_get_temp_dir(), 'je_import_').'.xlsx';
    $writer = new Writer();
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(JournalEntryTemplateExporter::HEADERS));
    $writer->addRow(Row::fromValuesWithStyles(
        ['ADD', $fecha, 'Aporte de capital', $fx['cash']->code, '', 'CRC', '500', '0', '', ''],
        null,
        [1 => $dateStyle]
    ));
    $writer->addRow(Row::fromValues(['', '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', '']));
    $writer->close();

    $file = new UploadedFile($path, 'asiento.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $response = $this->post(route('journal-entries.import'), ['file' => $file]);

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $response->assertRedirect(route('journal-entries.edit', $entry->id));

    expect($entry->document_date->format('Y-m-d'))->toBe($fecha->format('Y-m-d'));
});

it('importa documento_referencia y fecha_documento_referencia, distintos por línea', function () {
    $fx = journalHttpFixture();

    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), 'Dos facturas en un asiento', $fx['cash']->code, '', 'CRC', '500', '0', '', '', 'Factura-4521', '2026-01-10'],
        ['', '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', '', 'Factura-4522', '2026-01-12'],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $cashDetail = $entry->details->firstWhere('account_id', $fx['cash']->id);
    $capitalDetail = $entry->details->firstWhere('account_id', $fx['capital']->id);

    expect($cashDetail->reference_document)->toBe('Factura-4521')
        ->and($cashDetail->reference_document_date->format('Y-m-d'))->toBe('2026-01-10')
        ->and($capitalDetail->reference_document)->toBe('Factura-4522')
        ->and($capitalDetail->reference_document_date->format('Y-m-d'))->toBe('2026-01-12');
});

it('rechaza importar una fecha_documento_referencia inválida', function () {
    $fx = journalHttpFixture();

    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), '', $fx['cash']->code, '', 'CRC', '500', '0', '', '', 'Factura-1', 'no-es-fecha'],
        ['', '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', '', '', ''],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])
        ->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'fecha de documento de referencia')));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('importa una línea con socio de negocio, resolviendo su cuenta de control automáticamente', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), 'Venta a crédito', '', $partner->code, 'CRC', '300', '0', '', ''],
        ['', '', '', $fx['capital']->code, '', 'CRC', '0', '300', '', ''],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $cxcDetail = $entry->details->firstWhere('business_partner_id', $partner->id);

    expect($cxcDetail->account_id)->toBe($cxc->id)
        ->and($cxcDetail->debit_local)->toEqual('300.00');
});

it('un asiento importado puede quedar sin cuadrar y sin centro de costo exigido, porque queda como preliminar', function () {
    $fx = journalHttpFixture();
    $gasto = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '6-01-01-01-001', 'requires_cost_center' => true,
    ]);

    // Una sola línea, sin la contrapartida todavía, y sin centro de costo
    // aunque la cuenta lo exige — a propósito, para probar que la carga
    // masiva es tan tolerante como "Guardar como preliminar" manual.
    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), 'Falta terminar', $gasto->code, '', 'CRC', '100', '0', '', ''],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->status)->toBe('draft')
        ->and($entry->details)->toHaveCount(1);
});

it('rechaza importar si una cuenta del archivo no existe en la compañía, y no crea nada', function () {
    $fx = journalHttpFixture();

    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), 'Aporte de capital', 'CUENTA-INEXISTENTE', '', 'CRC', '500', '0', '', ''],
        ['', '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', ''],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])
        ->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'CUENTA-INEXISTENTE')));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza importar un archivo sin tipo de documento en la primera fila', function () {
    $fx = journalHttpFixture();

    $file = makeJournalEntryXlsx([
        ['', now()->format('Y-m-d'), '', $fx['cash']->code, '', 'CRC', '500', '0', '', ''],
        ['', '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', ''],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])
        ->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'tipo de documento')));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza una línea que indica cuenta y socio a la vez', function () {
    $fx = journalHttpFixture();
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), '', $fx['cash']->code, $partner->code, 'CRC', '500', '0', '', ''],
        ['', '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', ''],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])
        ->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'cuenta y socio')));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza una fila donde el tipo de documento no coincide con la primera fila', function () {
    $fx = journalHttpFixture();
    $otherType = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'OTR']);

    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), '', $fx['cash']->code, '', 'CRC', '500', '0', '', ''],
        [$otherType->code, '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', ''],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])
        ->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'no coincide')));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('la importación respeta la compañía activa: no resuelve una cuenta de otra compañía aunque el código coincida', function () {
    $fx = journalHttpFixture();
    $companyB = Company::factory()->create();
    ChartOfAccount::factory()->create(['company_id' => $companyB->id, 'code' => 'SOLO-EN-B']);

    $file = makeJournalEntryXlsx([
        ['ADD', now()->format('Y-m-d'), '', 'SOLO-EN-B', '', 'CRC', '500', '0', '', ''],
        ['', '', '', $fx['capital']->code, '', 'CRC', '0', '500', '', ''],
    ]);

    $this->post(route('journal-entries.import'), ['file' => $file])
        ->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'SOLO-EN-B')));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

// --- IVA por línea: cuenta vinculada a una tarifa (soportado/devengado) --

it('el formulario de creación trae las cuentas con su tarifa de IVA vinculada, para el selector por línea', function () {
    $fx = journalHttpFixture();
    $taxRate = TaxRate::factory()->create(['code' => 'IVA-13', 'percentage' => '13.00']);
    $ivaSoportado = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-04-01-001',
        'tax_classification' => 'iva_soportado', 'tax_rate_id' => $taxRate->id,
    ]);

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->where('accounts', fn ($accounts) => collect($accounts)
                ->firstWhere('id', $ivaSoportado->id)['tax_rate']['percentage'] === '13.00')
        );
});

it('contabiliza un gasto con IVA soportado: la línea de impuesto queda enlazada a journal_detail_taxes', function () {
    $fx = journalHttpFixture();
    $taxRate = TaxRate::factory()->create(['code' => 'IVA-13', 'percentage' => '13.00', 'effective_from' => '2019-07-01']);
    $ivaSoportado = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-04-01-001',
        'tax_classification' => 'iva_soportado', 'tax_rate_id' => $taxRate->id,
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Gastos de papelería',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 1130],
            [
                'account_id' => $ivaSoportado->id, 'currency_id' => $fx['company']->local_currency_id,
                'debit' => 130, 'credit' => 0, 'tax_rate_id' => $taxRate->id, 'taxable_base' => 1000,
            ],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $taxDetail = $entry->details->firstWhere('account_id', $ivaSoportado->id);

    $tax = JournalDetailTax::where('journal_detail_id', $taxDetail->id)->sole();
    expect($tax->tax_rate_id)->toBe($taxRate->id)
        ->and($tax->taxable_base)->toEqual('1000.00')
        ->and($tax->tax_amount)->toEqual('130.00');
});

it('rechaza contabilizar con un monto de IVA que no corresponde a la tarifa, con el error del service', function () {
    $fx = journalHttpFixture();
    $taxRate = TaxRate::factory()->create(['code' => 'IVA-13', 'percentage' => '13.00', 'effective_from' => '2019-07-01']);
    $ivaSoportado = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-04-01-001',
        'tax_classification' => 'iva_soportado', 'tax_rate_id' => $taxRate->id,
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 1999],
            [
                'account_id' => $ivaSoportado->id, 'currency_id' => $fx['company']->local_currency_id,
                'debit' => 999, 'credit' => 0, 'tax_rate_id' => $taxRate->id, 'taxable_base' => 1000,
            ],
        ],
    ])->assertSessionHasErrors('lines');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza un tax_rate_id que no existe en la línea de un asiento', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            [
                'account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id,
                'debit' => 130, 'credit' => 0, 'tax_rate_id' => 999999, 'taxable_base' => 1000,
            ],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 130],
        ],
    ])->assertSessionHasErrors('lines.0.tax_rate_id');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('muestra el detalle de un asiento con el desglose de IVA por línea', function () {
    $fx = journalHttpFixture();
    $taxRate = TaxRate::factory()->create(['code' => 'IVA-13', 'percentage' => '13.00', 'effective_from' => '2019-07-01']);
    $ivaSoportado = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-04-01-001',
        'tax_classification' => 'iva_soportado', 'tax_rate_id' => $taxRate->id,
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 1130],
            [
                'account_id' => $ivaSoportado->id, 'currency_id' => $fx['company']->local_currency_id,
                'debit' => 130, 'credit' => 0, 'tax_rate_id' => $taxRate->id, 'taxable_base' => 1000,
            ],
        ],
    ]);
    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->get(route('journal-entries.show', $entry->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Show')
            ->where('entry.lines', fn ($lines) => collect($lines)
                ->firstWhere('account.id', $ivaSoportado->id)['tax']['tax_amount'] === '130.00')
        );
});

// --- Clave numérica electrónica (Hacienda) ---------------------------------
// Por LÍNEA, no por asiento (ver JournalEntryController::validated()): un
// mismo asiento puede juntar varias facturas de compra a la vez, cada una
// con su propia clave — 'lines.*.electronic_key' valida formato/duplicado
// cruzado por línea; el closure sobre 'lines' valida "sin repetir dentro del
// mismo envío" y "al menos una si el tipo de documento la exige".

it('el formulario de creación trae si cada tipo de documento exige clave electrónica', function () {
    $fx = journalHttpFixture();
    $fce = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'FCE', 'requires_electronic_key' => true]);

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->where('documentTypes', fn ($types) => collect($types)->firstWhere('id', $fx['add']->id)['requires_electronic_key'] === false
                && collect($types)->firstWhere('id', $fce->id)['requires_electronic_key'] === true)
        );
});

it('exige la clave electrónica al contabilizar un tipo de documento que la requiere', function () {
    $fx = journalHttpFixture();
    $fce = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'FCE', 'requires_electronic_key' => true]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fce->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('lines');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('no exige la clave electrónica al guardar como preliminar, aunque el tipo de documento la requiera', function () {
    $fx = journalHttpFixture();
    $fce = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'FCE', 'requires_electronic_key' => true]);

    $this->post(route('journal-entries.store'), [
        'intent' => 'draft',
        'document_type_id' => $fce->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
        ],
    ])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->status)->toBe('draft')
        ->and($entry->details->first()->electronic_key)->toBeNull();
});

it('contabiliza con una clave electrónica válida de 50 dígitos en una línea', function () {
    $fx = journalHttpFixture();
    $fce = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'FCE', 'requires_electronic_key' => true]);
    $key = str_repeat('3', 50);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fce->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'electronic_key' => $key],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->details->firstWhere('account_id', $fx['cash']->id)->electronic_key)->toBe($key);
    expect($entry->details->firstWhere('account_id', $fx['capital']->id)->electronic_key)->toBeNull();
});

it('rechaza una clave electrónica que no tiene exactamente 50 dígitos', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'electronic_key' => str_repeat('4', 49)],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('lines.0.electronic_key');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza una clave electrónica con caracteres no numéricos', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'electronic_key' => str_repeat('A', 50)],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('lines.0.electronic_key');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('rechaza dos líneas del mismo asiento con la misma clave electrónica', function () {
    $fx = journalHttpFixture();
    $key = str_repeat('9', 50);
    $expense = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-001']);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'electronic_key' => $key],
            ['account_id' => $expense->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 50, 'credit' => 0, 'electronic_key' => $key],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 150],
        ],
    ])->assertSessionHasErrors('lines');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('contabiliza un asiento que junta varias facturas de compra, cada línea con su propia clave electrónica', function () {
    $fx = journalHttpFixture();
    $fce = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'FCE', 'requires_electronic_key' => true]);
    $expense1 = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-001']);
    $expense2 = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-002']);
    $key1 = str_repeat('1', 50);
    $key2 = str_repeat('2', 50);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fce->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $expense1->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'electronic_key' => $key1],
            ['account_id' => $expense2->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 50, 'credit' => 0, 'electronic_key' => $key2],
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 150],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->details->firstWhere('account_id', $expense1->id)->electronic_key)->toBe($key1);
    expect($entry->details->firstWhere('account_id', $expense2->id)->electronic_key)->toBe($key2);
});

it('rechaza una clave electrónica duplicada dentro de la misma compañía', function () {
    $fx = journalHttpFixture();
    $key = str_repeat('5', 50);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'electronic_key' => $key],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasNoErrors();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 200, 'credit' => 0, 'electronic_key' => $key],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 200],
        ],
    ])->assertSessionHasErrors('lines.0.electronic_key');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(1);
});

it('permite la misma clave electrónica en compañías distintas', function () {
    $fx = journalHttpFixture();
    $key = str_repeat('6', 50);

    $companyB = Company::factory()->create();
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $companyB, $typeB, new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput(
            ChartOfAccount::factory()->create(['company_id' => $companyB->id])->id,
            $companyB->local_currency_id, debit: 100, credit: 0,
            electronicKey: $key,
        )],
    );

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'electronic_key' => $key],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $fx['company']->id)->sole();
    expect($entry->details->firstWhere('account_id', $fx['cash']->id)->electronic_key)->toBe($key);
});

it('permite editar un borrador conservando su propia clave electrónica, sin rechazarla como duplicada', function () {
    $fx = journalHttpFixture();
    $key = str_repeat('7', 50);
    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, electronicKey: $key)],
    );

    $this->put(route('journal-entries.update', $draft->id), [
        'intent' => 'draft',
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 150, 'credit' => 0, 'electronic_key' => $key],
        ],
    ])->assertSessionHasNoErrors();

    expect($draft->fresh()->details->first()->electronic_key)->toBe($key);
});

it('muestra la clave electrónica de cada línea en el detalle del asiento', function () {
    $fx = journalHttpFixture();
    $key = str_repeat('8', 50);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'electronic_key' => $key],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ]);
    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->get(route('journal-entries.show', $entry->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Show')
            ->where('entry.lines.0.electronic_key', $key)
            ->where('entry.lines.1.electronic_key', null)
        );
});

// --- Tres fechas: documento (informativa), contabilización (rectora), vencimiento (encabezado + línea) ---

it('exige la fecha de contabilización', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasErrors('posting_date');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

it('guarda fecha de documento y fecha de contabilización por separado cuando difieren', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => '2026-01-05',
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->document_date->format('Y-m-d'))->toBe('2026-01-05')
        ->and($entry->posting_date->format('Y-m-d'))->toBe(now()->format('Y-m-d'));
});

it('una fecha de vencimiento de encabezado respalda a las líneas que no traen la suya propia', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'due_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0, 'due_date' => '2026-04-15'],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->due_date->format('Y-m-d'))->toBe('2026-03-01')
        ->and($entry->details->firstWhere('account_id', $fx['cash']->id)->due_date->format('Y-m-d'))->toBe('2026-04-15')
        ->and($entry->details->firstWhere('account_id', $fx['capital']->id)->due_date->format('Y-m-d'))->toBe('2026-03-01');
});

it('guarda y muestra un documento de referencia y su fecha distintos por línea', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            [
                'account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id,
                'debit' => 100, 'credit' => 0,
                'reference_document' => 'Factura-4521', 'reference_document_date' => '2026-01-10',
            ],
            [
                'account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id,
                'debit' => 0, 'credit' => 100,
                'reference_document' => 'Factura-4522', 'reference_document_date' => '2026-01-12',
            ],
        ],
    ])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->get(route('journal-entries.show', $entry->id))
        ->assertInertia(fn ($page) => $page
            ->where('entry.lines', fn ($lines) => collect($lines)
                ->contains(fn ($l) => $l['reference_document'] === 'Factura-4521' && $l['reference_document_date'] === '2026-01-10')
                && collect($lines)->contains(fn ($l) => $l['reference_document'] === 'Factura-4522' && $l['reference_document_date'] === '2026-01-12')
            )
        );
});

it('muestra la fecha de contabilización, de documento y de vencimiento en el detalle del asiento', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => '2026-01-05',
        'posting_date' => now()->format('Y-m-d'),
        'due_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertSessionHasNoErrors();
    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->get(route('journal-entries.show', $entry->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Show')
            ->where('entry.document_date', '2026-01-05')
            ->where('entry.posting_date', now()->format('Y-m-d'))
            ->where('entry.due_date', '2026-03-01')
        );
});

// --- Anulación y duplicado (ver PostJournalService::reverse()) ------------

it('corrige el vencimiento de una línea de un asiento ya contabilizado, sin tocar nada más', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-001', 'name' => 'Cliente uno', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    $entry = app(\App\Domains\Accounting\Services\PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput(
                $cxc->id, $fx['company']->local_currency_id, debit: 1000, credit: 0,
                businessPartnerId: $partner->id, dueDate: '2026-02-04', opensItem: true,
            ),
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
        'Venta a crédito'
    );

    $cxcDetail = $entry->details->firstWhere('business_partner_id', $partner->id);
    $openItem = \App\Domains\BusinessPartners\Models\BpOpenItem::where('origin_journal_detail_id', $cxcDetail->id)->sole();

    $this->put(route('journal-entries.lines.update-due-date', [$entry->id, $cxcDetail->id]), [
        'due_date' => '2026-03-20',
    ])->assertSessionHasNoErrors();

    expect($cxcDetail->fresh()->due_date->format('Y-m-d'))->toBe('2026-03-20')
        // Se mantiene sincronizado con la partida abierta que generó, que es
        // lo que de verdad leen antigüedad de saldos y proyección de cobros.
        ->and($openItem->fresh()->due_date->format('Y-m-d'))->toBe('2026-03-20')
        // Nada más de la línea (ni del asiento) cambió.
        ->and($cxcDetail->fresh()->debit_local)->toEqual('1000.00')
        ->and($entry->fresh()->status)->toBe('posted');
});

it('abre una partida retroactiva al definir el vencimiento de una línea que nunca marcó "abre partida"', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-002', 'name' => 'Cliente dos', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    // Sin dueDate ni opensItem: el mismo error real que motivó la reparación
    // masiva (OpenItemBackfillService) — el monto ya está contabilizado,
    // pero nunca quedó nada en bp_open_items para poder aplicarle un cobro.
    $entry = app(\App\Domains\Accounting\Services\PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput(
                $cxc->id, $fx['company']->local_currency_id, debit: 800, credit: 0, businessPartnerId: $partner->id,
            ),
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 800),
        ],
        'Venta a crédito sin partida'
    );

    $cxcDetail = $entry->details->firstWhere('business_partner_id', $partner->id);
    expect(\App\Domains\BusinessPartners\Models\BpOpenItem::where('origin_journal_detail_id', $cxcDetail->id)->exists())->toBeFalse();

    $this->put(route('journal-entries.lines.update-due-date', [$entry->id, $cxcDetail->id]), [
        'due_date' => '2026-04-10',
    ])->assertSessionHasNoErrors();

    $openItem = \App\Domains\BusinessPartners\Models\BpOpenItem::where('origin_journal_detail_id', $cxcDetail->id)->sole();

    expect($openItem->balance)->toEqual('800.00')
        ->and($openItem->original_amount)->toEqual('800.00')
        ->and($openItem->status)->toBe('open')
        ->and($openItem->due_date->format('Y-m-d'))->toBe('2026-04-10')
        ->and($cxcDetail->fresh()->due_date->format('Y-m-d'))->toBe('2026-04-10');
});

it('vincula un socio de negocio a una línea posteada sin uno, y abre su partida si ya tenía vencimiento', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-003', 'name' => 'Cliente sin registrar a tiempo', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    // Se contabilizó ANTES de que el socio existiera: la línea queda contra
    // la cuenta de control ($cxc) pero sin business_partner_id — mismo caso
    // real que un saldo inicial cargado antes de terminar de dar de alta a
    // los socios. Sí tiene vencimiento (dueDate directo, sin opensItem),
    // simulando que ya se corrigió con updateLineDueDate.
    $entry = app(\App\Domains\Accounting\Services\PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($cxc->id, $fx['company']->local_currency_id, debit: 500, credit: 0, dueDate: '2026-05-01'),
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 500),
        ],
        'Saldo inicial sin socio todavía'
    );

    $detail = $entry->details->firstWhere('account_id', $cxc->id);

    $this->put(route('journal-entries.lines.link-business-partner', [$entry->id, $detail->id]), [
        'business_partner_id' => $partner->id,
    ])->assertSessionHasNoErrors();

    $openItem = \App\Domains\BusinessPartners\Models\BpOpenItem::where('origin_journal_detail_id', $detail->id)->sole();

    expect($detail->fresh()->business_partner_id)->toBe($partner->id)
        ->and($openItem->business_partner_id)->toBe($partner->id)
        ->and($openItem->balance)->toEqual('500.00')
        ->and($openItem->due_date->format('Y-m-d'))->toBe('2026-05-01')
        // La cuenta y el monto de la línea no cambiaron.
        ->and($detail->fresh()->account_id)->toBe($cxc->id)
        ->and($detail->fresh()->debit_local)->toEqual('500.00');
});

it('rechaza vincular un socio cuya cuenta de control no coincide con la cuenta ya contabilizada en la línea', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $otherAccount = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-02-001']);

    $wrongPartner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-004', 'name' => 'Socio de otra cuenta', 'type' => 'client',
        'gl_account_id' => $otherAccount->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);

    $entry = app(\App\Domains\Accounting\Services\PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($cxc->id, $fx['company']->local_currency_id, debit: 500, credit: 0),
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 500),
        ],
    );

    $detail = $entry->details->firstWhere('account_id', $cxc->id);

    $this->put(route('journal-entries.lines.link-business-partner', [$entry->id, $detail->id]), [
        'business_partner_id' => $wrongPartner->id,
    ])->assertSessionHasErrors('business_partner_id');

    expect($detail->fresh()->business_partner_id)->toBeNull();
});

it('rechaza vincular un socio a una línea que ya tiene uno', function () {
    $fx = journalHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::create([
        'company_id' => $fx['company']->id, 'code' => 'C-005', 'name' => 'Cliente ya vinculado', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id, 'status' => 'active',
    ]);
    $otherPartner = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'gl_account_id' => $cxc->id]);

    $entry = app(\App\Domains\Accounting\Services\PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($cxc->id, $fx['company']->local_currency_id, debit: 500, credit: 0, businessPartnerId: $partner->id),
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 500),
        ],
    );

    $detail = $entry->details->firstWhere('account_id', $cxc->id);

    $this->put(route('journal-entries.lines.link-business-partner', [$entry->id, $detail->id]), [
        'business_partner_id' => $otherPartner->id,
    ])->assertSessionHasErrors('business_partner_id');

    expect($detail->fresh()->business_partner_id)->toBe($partner->id);
});

it('rechaza vincular un socio de negocio en una línea de un asiento en borrador', function () {
    $fx = journalHttpFixture();

    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 500, credit: 0, allowZeroAmount: true),
        ],
        'Preliminar'
    );

    $partner = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'gl_account_id' => $fx['cash']->id]);
    $detail = $draft->details->first();

    $this->put(route('journal-entries.lines.link-business-partner', [$draft->id, $detail->id]), [
        'business_partner_id' => $partner->id,
    ])->assertNotFound();
});

it('rechaza corregir el vencimiento de una línea de un asiento en borrador (se edita completo desde el formulario)', function () {
    $fx = journalHttpFixture();

    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 500, credit: 0, allowZeroAmount: true),
        ],
        'Preliminar'
    );

    $detail = $draft->details->first();

    $this->put(route('journal-entries.lines.update-due-date', [$draft->id, $detail->id]), [
        'due_date' => '2026-03-20',
    ])->assertNotFound();
});

it('anula un asiento contabilizado y redirige al detalle del asiento de reversión', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $original = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $response = $this->post(route('journal-entries.reverse', $original->id));

    $reversal = JournalEntry::withoutGlobalScope(CompanyScope::class)->where('reversal_of_id', $original->id)->sole();
    $response->assertRedirect(route('journal-entries.show', $reversal->id));

    expect($original->fresh()->status)->toBe('voided')
        ->and($reversal->status)->toBe('posted');
});

it('el detalle de un asiento anulado muestra el enlace al asiento de reversión, y viceversa', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $original = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->post(route('journal-entries.reverse', $original->id));
    $reversal = JournalEntry::withoutGlobalScope(CompanyScope::class)->where('reversal_of_id', $original->id)->sole();

    $this->get(route('journal-entries.show', $original->id))
        ->assertInertia(fn ($page) => $page
            ->where('entry.reversed_by.id', $reversal->id)
            ->where('entry.reversal_of', null)
        );

    $this->get(route('journal-entries.show', $reversal->id))
        ->assertInertia(fn ($page) => $page
            ->where('entry.reversal_of.id', $original->id)
            ->where('entry.reversed_by', null)
        );
});

it('rechaza anular un borrador y muestra el error del service', function () {
    $fx = journalHttpFixture();
    $draft = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $fx['company'],
        $fx['add'],
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );

    $this->post(route('journal-entries.reverse', $draft->id))->assertSessionHasErrors('reversal');

    expect($draft->fresh()->status)->toBe('draft');
});

it('rechaza anular un asiento de otra compañía (404, igual que ver/editar/borrar)', function () {
    journalHttpFixture();
    $companyB = Company::factory()->create();
    $accountB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $accountB2 = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    $fiscalYearB = FiscalYear::factory()->create(['company_id' => $companyB->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYearB->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);
    ExchangeRate::factory()->create([
        'company_id' => $companyB->id,
        'currency_id' => $companyB->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);
    $entryB = app(\App\Domains\Accounting\Services\PostJournalService::class)->post(
        $companyB,
        $typeB,
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($accountB->id, $companyB->local_currency_id, debit: 100, credit: 0),
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($accountB2->id, $companyB->local_currency_id, debit: 0, credit: 100),
        ],
    );

    $this->post(route('journal-entries.reverse', $entryB->id))->assertNotFound();

    expect($entryB->fresh()->status)->toBe('posted');
});

it('duplicar precarga el formulario de creación con las líneas de otro asiento, sin id ni número de serie', function () {
    $fx = journalHttpFixture();
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $fx['company']->id, 'document_type_id' => $fx['add']->id,
        'range_from' => 1, 'range_to' => 100, 'next_number' => 1,
    ]);

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'number_series_id' => $series->id,
        'description' => 'Aporte de capital',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $original = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->get(route('journal-entries.duplicate', $original->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Create')
            ->where('entry.id', null)
            ->where('entry.number_series_id', null)
            ->where('entry.description', 'Aporte de capital')
            ->has('entry.lines', 2)
            ->where('entry.lines.0.debit', '500.00')
        );
});

it('duplicar y contabilizar el formulario precargado crea un asiento nuevo, sin tocar el original', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $original = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $prefilled = $this->get(route('journal-entries.duplicate', $original->id))
        ->assertOk()
        ->viewData('page')['props']['entry'];

    expect($prefilled['id'])->toBeNull();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $prefilled['document_type_id'],
        'document_date' => $prefilled['document_date'],
        'posting_date' => $prefilled['posting_date'],
        'description' => $prefilled['description'],
        'lines' => collect($prefilled['lines'])->map(fn ($l) => [
            'account_id' => $l['account_id'],
            'currency_id' => $l['currency_id'],
            'debit' => $l['debit'],
            'credit' => $l['credit'],
        ])->all(),
    ])->assertRedirect(route('journal-entries.index'));

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(2)
        ->and($original->fresh()->status)->toBe('posted');
});

// --- Búsqueda liviana de documentos (para "buscar" del toolbar y "cargar
// desde un documento existente" en Create.vue) -----------------------------

it('busca un asiento por número de documento', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Aporte inicial de capital',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->getJson(route('journal-entries.search').'?q=capital')
        ->assertOk()
        ->assertJsonFragment(['id' => $entry->id, 'description' => 'Aporte inicial de capital']);
});

it('la búsqueda de asientos exige al menos 2 caracteres y no explota con una consulta vacía', function () {
    journalHttpFixture();

    $this->getJson(route('journal-entries.search').'?q=a')->assertOk()->assertExactJson([]);
    $this->getJson(route('journal-entries.search'))->assertOk()->assertExactJson([]);
});

it('la búsqueda de asientos no trae resultados de otra compañía', function () {
    $fx = journalHttpFixture();
    $companyB = Company::factory()->create();
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $companyB,
        $typeB,
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput(
            ChartOfAccount::factory()->create(['company_id' => $companyB->id])->id,
            $companyB->local_currency_id,
            debit: 100,
            credit: 0
        )],
        'Solo visible en la compañía B',
    );

    $this->getJson(route('journal-entries.search').'?q=compañía B')->assertOk()->assertExactJson([]);
});

// --- Navegación entre documentos (primero/anterior/siguiente/último) ------

it('el detalle de un asiento trae el id del anterior y el siguiente en orden cronológico', function () {
    // Misma posting_date para las 3 (evita cruzar de mes con addDays cerca de
    // fin de mes, lo que tumbaría el fixture de período fiscal abierto) — con
    // la fecha empatada, siblingEntryIds() desempata por id ascendente, que
    // es justo el orden en que se crean acá.
    $fx = journalHttpFixture();

    $ids = collect(range(1, 3))->map(function () use ($fx) {
        $this->post(route('journal-entries.store'), [
            'document_type_id' => $fx['add']->id,
            'document_date' => now()->format('Y-m-d'),
            'posting_date' => now()->format('Y-m-d'),
            'lines' => [
                ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 100],
            ],
        ])->assertRedirect(route('journal-entries.index'));

        return JournalEntry::withoutGlobalScope(CompanyScope::class)->orderByDesc('id')->value('id');
    })->values();

    expect($ids->unique())->toHaveCount(3);

    $this->get(route('journal-entries.show', $ids[1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('nav.prev', $ids[0])
            ->where('nav.next', $ids[2])
            ->where('nav.first', $ids[0])
            ->where('nav.last', $ids[2])
        );
});

// --- Exportar un asiento a XLSX (botón "Exportar" del toolbar) ------------

it('exporta un asiento contabilizado a xlsx', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Aporte de capital',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $response = $this->get(route('journal-entries.export', $entry->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
});

it('exporta un asiento contabilizado a pdf', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Aporte de capital',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $response = $this->get(route('journal-entries.export-pdf', $entry->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('muestra la pantalla de "presentar documento" con el encabezado de identidad de empresa', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'description' => 'Aporte de capital',
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ]);
    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    $this->get(route('journal-entries.presentation', $entry->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Presentation')
            ->where('entry.id', $entry->id)
            ->where('entry.description', 'Aporte de capital')
            ->has('entry.lines', 2)
            ->where('header.company_name', fn ($name) => filled($name))
        );
});

it('presentar/exportar un asiento de otra compañía da 404', function () {
    journalHttpFixture();
    $companyB = Company::factory()->create();
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    $draftB = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $companyB,
        $typeB,
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput(
            ChartOfAccount::factory()->create(['company_id' => $companyB->id])->id,
            $companyB->local_currency_id,
            debit: 100,
            credit: 0
        )],
    );

    $this->get(route('journal-entries.presentation', $draftB->id))->assertNotFound();
    $this->get(route('journal-entries.export-pdf', $draftB->id))->assertNotFound();
});

it('exporta un asiento de otra compañía da 404', function () {
    journalHttpFixture();
    $companyB = Company::factory()->create();
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    $draftB = app(\App\Domains\Accounting\Services\PostJournalService::class)->saveDraft(
        $companyB,
        $typeB,
        new DateTime(now()->format('Y-m-d')),
        new DateTime(now()->format('Y-m-d')),
        [new \App\Domains\Accounting\DataTransferObjects\JournalLineInput(
            ChartOfAccount::factory()->create(['company_id' => $companyB->id])->id,
            $companyB->local_currency_id,
            debit: 100,
            credit: 0
        )],
    );

    $this->get(route('journal-entries.export', $draftB->id))->assertNotFound();
});

// --- Auto-cuadre de un preliminar contra la cuenta puente ------------------

it('guardar un preliminar que no cuadra lo balancea automáticamente contra la cuenta puente 9-00-00-00-000', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'intent' => 'draft',
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 300],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $suspense = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
        ->where('company_id', $fx['company']->id)->where('code', '9-00-00-00-000')->sole();
    $bridgeDetail = $entry->details->firstWhere('account_id', $suspense->id);

    expect($entry->status)->toBe('draft')
        ->and($bridgeDetail)->not->toBeNull()
        ->and($bridgeDetail->credit_local)->toEqual('200.00')
        ->and($bridgeDetail->debit_local)->toEqual('0.00');
});

it('guardar un preliminar que ya cuadra no toca la cuenta puente', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'intent' => 'draft',
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 500],
        ],
    ])->assertRedirect(route('journal-entries.index'));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();

    expect($entry->details)->toHaveCount(2)
        ->and(ChartOfAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $fx['company']->id)->where('code', '9-00-00-00-000')->exists())->toBeFalse();
});

it('un preliminar que no cuadra se sigue rechazando al intentar contabilizarlo formalmente (intent=post)', function () {
    $fx = journalHttpFixture();

    $this->post(route('journal-entries.store'), [
        'document_type_id' => $fx['add']->id,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [
            ['account_id' => $fx['cash']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $fx['capital']->id, 'currency_id' => $fx['company']->local_currency_id, 'debit' => 0, 'credit' => 300],
        ],
    ])->assertSessionHasErrors('lines');

    expect(JournalEntry::withoutGlobalScope(CompanyScope::class)->count())->toBe(0);
});

// --- Buscador y exportación del listado de Registros (JournalEntries/Index) -

function postSimpleEntry(array $fx, string $description, string $postingDate, ?\App\Domains\Core\Models\DocumentType $documentType = null): void
{
    app(\App\Domains\Accounting\Services\PostJournalService::class)->post(
        $fx['company'], $documentType ?? $fx['add'], new DateTime($postingDate), new DateTime($postingDate),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0),
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($fx['capital']->id, $fx['company']->local_currency_id, debit: 0, credit: 100),
        ],
        description: $description,
    );
}

it('el listado de asientos filtra por número de documento', function () {
    $fx = journalHttpFixture();
    postSimpleEntry($fx, 'Aporte uno', now()->format('Y-m-d'));
    postSimpleEntry($fx, 'Aporte dos', now()->format('Y-m-d'));

    $second = JournalEntry::withoutGlobalScope(CompanyScope::class)->orderByDesc('id')->first();

    $this->get(route('journal-entries.index', ['document_number' => $second->document_number]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('JournalEntries/Index')
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $second->id)
        );
});

it('el listado de asientos filtra por tipo de documento', function () {
    $fx = journalHttpFixture();
    $otherType = DocumentType::factory()->create(['company_id' => $fx['company']->id, 'code' => 'OTR']);

    postSimpleEntry($fx, 'Del tipo ADD', now()->format('Y-m-d'));
    postSimpleEntry($fx, 'Del tipo OTR', now()->format('Y-m-d'), $otherType);

    $this->get(route('journal-entries.index', ['document_type_id' => $otherType->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.description', 'Del tipo OTR')
        );
});

it('el listado de asientos filtra por rango de fechas de contabilización', function () {
    $fx = journalHttpFixture();
    // La fecha de cambio del fixture es HOY — para poder contabilizar "Viejo"
    // unos días antes hace falta un tipo de cambio vigente para esa fecha
    // también (PostJournalService lo exige aunque las líneas sean en moneda
    // local, ver docs/decisiones.md 2026-08-22).
    ExchangeRate::factory()->create([
        'company_id' => $fx['company']->id,
        'currency_id' => $fx['company']->foreign_currency_id,
        'rate_date' => now()->subDays(30)->format('Y-m-d'),
        'rate' => '520.000000',
    ]);
    postSimpleEntry($fx, 'Viejo', now()->subDays(10)->format('Y-m-d'));
    postSimpleEntry($fx, 'Reciente', now()->format('Y-m-d'));

    $this->get(route('journal-entries.index', ['from' => now()->subDays(2)->format('Y-m-d')]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.description', 'Reciente')
        );
});

it('exporta el listado de asientos filtrado a xlsx', function () {
    $fx = journalHttpFixture();
    postSimpleEntry($fx, 'Aporte de capital', now()->format('Y-m-d'));

    $response = $this->get(route('journal-entries.list-export'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
});

it('exporta el listado de asientos filtrado a pdf', function () {
    $fx = journalHttpFixture();
    postSimpleEntry($fx, 'Aporte de capital', now()->format('Y-m-d'));

    $response = $this->get(route('journal-entries.list-export-pdf'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('el listado de asientos no trae ni exporta documentos de otra compañía', function () {
    $fx = journalHttpFixture();
    postSimpleEntry($fx, 'Propio', now()->format('Y-m-d'));

    $companyB = Company::factory()->create();
    $typeB = DocumentType::factory()->create(['company_id' => $companyB->id]);
    $accountB1 = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $accountB2 = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $fiscalYearB = FiscalYear::factory()->create(['company_id' => $companyB->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYearB->id, 'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'), 'end_date' => now()->endOfMonth()->format('Y-m-d'), 'status' => 'open',
    ]);
    ExchangeRate::factory()->create([
        'company_id' => $companyB->id, 'currency_id' => $companyB->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'), 'rate' => '520.000000',
    ]);
    app(\App\Domains\Accounting\Services\PostJournalService::class)->post(
        $companyB, $typeB, new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($accountB1->id, $companyB->local_currency_id, debit: 100, credit: 0),
            new \App\Domains\Accounting\DataTransferObjects\JournalLineInput($accountB2->id, $companyB->local_currency_id, debit: 0, credit: 100),
        ],
        description: 'Ajeno',
    );

    $this->get(route('journal-entries.index'))
        ->assertInertia(fn ($page) => $page->has('entries.data', 1)->where('entries.data.0.description', 'Propio'));

    // La isolación real ya la prueba el index() de arriba (1 sola fila,
    // "Propio") — acá solo confirmamos que exportar no falla con datos de
    // otra compañía en la base (CompanyScope aplica igual al query del export).
    $this->get(route('journal-entries.list-export'))->assertOk();
});
