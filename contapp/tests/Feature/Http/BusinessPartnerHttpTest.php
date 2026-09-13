<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function businessPartnerHttpFixture(): array
{
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001']);
    $sales = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '4-01-01-01-001']);

    $fve = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $trb = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    $partner = BusinessPartner::create([
        'company_id' => $company->id,
        'code' => 'C-001',
        'name' => 'Cliente de prueba',
        'type' => 'client',
        'gl_account_id' => $cxc->id,
        'currency_id' => $company->local_currency_id,
        'status' => 'active',
    ]);

    return compact('user', 'company', 'cash', 'cxc', 'sales', 'fve', 'trb', 'partner');
}

it('solo lista los socios de negocio de la compañía activa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $cxcA = ChartOfAccount::factory()->create(['company_id' => $companyA->id]);
    $cxcB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);

    $partnerA = BusinessPartner::create([
        'company_id' => $companyA->id, 'code' => 'C-001', 'name' => 'A', 'type' => 'client',
        'gl_account_id' => $cxcA->id, 'currency_id' => $companyA->local_currency_id, 'status' => 'active',
    ]);
    BusinessPartner::create([
        'company_id' => $companyB->id, 'code' => 'C-002', 'name' => 'B', 'type' => 'client',
        'gl_account_id' => $cxcB->id, 'currency_id' => $companyB->local_currency_id, 'status' => 'active',
    ]);

    logInAsCompanyUser($companyA);

    $this->get(route('business-partners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('BusinessPartners/Index')
            ->has('partners', 1)
            ->where('partners.0.code', $partnerA->code)
        );
});

it('crea un socio de negocio desde el formulario', function () {
    $fx = businessPartnerHttpFixture();

    $this->post(route('business-partners.store'), [
        'code' => 'C-099',
        'name' => 'Nuevo cliente',
        'type' => 'client',
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertRedirect();

    expect(BusinessPartner::where('code', 'C-099')->exists())->toBeTrue();
});

it('rechaza vincular un socio de negocio a una cuenta que no acepta movimientos', function () {
    $fx = businessPartnerHttpFixture();
    $summary = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-02-00-000', 'accepts_posting' => false,
    ]);

    $this->post(route('business-partners.store'), [
        'code' => 'C-098',
        'name' => 'Cliente inválido',
        'type' => 'client',
        'gl_account_id' => $summary->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasErrors('gl_account_id');

    expect(BusinessPartner::where('code', 'C-098')->exists())->toBeFalse();
});

it('crea un socio con los datos de contacto y usa hoy como fecha de inicio si no se indica otra', function () {
    $fx = businessPartnerHttpFixture();

    $this->post(route('business-partners.store'), [
        'code' => 'C-100',
        'name' => 'Cliente con contacto',
        'type' => 'client',
        'email' => 'contacto@ejemplo.com',
        'economic_activity_code' => '620100',
        'phone' => '2222-3333',
        'contact_name' => 'María Pérez',
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasNoErrors();

    $partner = BusinessPartner::where('code', 'C-100')->sole();

    expect($partner->email)->toBe('contacto@ejemplo.com')
        ->and($partner->economic_activity_code)->toBe('620100')
        ->and($partner->contact_name)->toBe('María Pérez')
        ->and($partner->partner_since->format('Y-m-d'))->toBe(now()->format('Y-m-d'));
});

it('respeta una fecha de inicio distinta a hoy cuando se está migrando un socio histórico', function () {
    $fx = businessPartnerHttpFixture();

    $this->post(route('business-partners.store'), [
        'code' => 'C-101',
        'name' => 'Cliente antiguo',
        'type' => 'client',
        'partner_since' => '2019-03-01',
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ]);

    expect(BusinessPartner::where('code', 'C-101')->sole()->partner_since->format('Y-m-d'))->toBe('2019-03-01');
});

it('edita un socio de negocio existente, incluyendo su estado', function () {
    $fx = businessPartnerHttpFixture();

    $this->put(route('business-partners.update', $fx['partner']->id), [
        'code' => $fx['partner']->code,
        'name' => 'Cliente de prueba renombrado',
        'type' => 'both',
        'email' => 'nuevo@ejemplo.com',
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
        'status' => 'inactive',
    ])->assertSessionHasNoErrors();

    $partner = $fx['partner']->fresh();

    expect($partner->name)->toBe('Cliente de prueba renombrado')
        ->and($partner->email)->toBe('nuevo@ejemplo.com')
        ->and($partner->status)->toBe('inactive');
});

it('rechaza editar un socio de negocio de otra compañía', function () {
    $companyB = Company::factory()->create();
    $cxcB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $partnerB = BusinessPartner::create([
        'company_id' => $companyB->id, 'code' => 'C-777', 'name' => 'Ajeno', 'type' => 'client',
        'gl_account_id' => $cxcB->id, 'currency_id' => $companyB->local_currency_id, 'status' => 'active',
    ]);

    logInAsCompanyUser();

    $this->get(route('business-partners.edit', $partnerB->id))->assertNotFound();
    $this->put(route('business-partners.update', $partnerB->id), [
        'code' => $partnerB->code, 'name' => 'Intento ajeno', 'type' => 'client',
        'gl_account_id' => $cxcB->id, 'currency_id' => $companyB->local_currency_id,
    ])->assertNotFound();
});

it('muestra las partidas abiertas de un socio', function () {
    $fx = businessPartnerHttpFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0,
                businessPartnerId: $fx['partner']->id, dueDate: now()->addDays(30)->format('Y-m-d'), opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
    );

    $this->get(route('business-partners.open-items', $fx['partner']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('BusinessPartners/OpenItems')
            ->has('openItems', 1)
            ->where('openItems.0.balance', '1000.00')
        );
});

it('aplica un pago completo a una partida abierta desde el formulario', function () {
    $fx = businessPartnerHttpFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0,
                businessPartnerId: $fx['partner']->id, dueDate: now()->addDays(30)->format('Y-m-d'), opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
    );

    $openItem = BpOpenItem::where('business_partner_id', $fx['partner']->id)->sole();

    $this->post(route('business-partners.open-items.apply', [$fx['partner'], $openItem]), [
        'payment_account_id' => $fx['cash']->id,
        'amount' => 1000,
        'applied_date' => now()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    expect($openItem->fresh()->status)->toBe('closed');
});

it('corrige el vencimiento de una partida abierta sin tocar el asiento que la originó', function () {
    $fx = businessPartnerHttpFixture();

    $entry = app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0,
                businessPartnerId: $fx['partner']->id, dueDate: '2026-02-04', opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
    );

    $openItem = BpOpenItem::where('business_partner_id', $fx['partner']->id)->sole();
    $originalDetailDueDate = $entry->details->firstWhere('business_partner_id', $fx['partner']->id)->due_date->format('Y-m-d');

    $this->put(route('business-partners.open-items.update-due-date', [$fx['partner'], $openItem]), [
        'due_date' => '2026-03-15',
    ])->assertSessionHasNoErrors();

    expect($openItem->fresh()->due_date->format('Y-m-d'))->toBe('2026-03-15')
        // El asiento contabilizado original queda intacto — la corrección
        // vive solo en bp_open_items, nunca en journal_details.
        ->and($entry->fresh()->details->firstWhere('business_partner_id', $fx['partner']->id)->due_date->format('Y-m-d'))->toBe($originalDetailDueDate)
        ->and($originalDetailDueDate)->toBe('2026-02-04');
});

it('rechaza corregir el vencimiento de una partida ya cerrada', function () {
    $fx = businessPartnerHttpFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0,
                businessPartnerId: $fx['partner']->id, dueDate: '2026-02-04', opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
    );

    $openItem = BpOpenItem::where('business_partner_id', $fx['partner']->id)->sole();

    $this->post(route('business-partners.open-items.apply', [$fx['partner'], $openItem]), [
        'payment_account_id' => $fx['cash']->id,
        'amount' => 1000,
        'applied_date' => now()->format('Y-m-d'),
    ]);
    expect($openItem->fresh()->status)->toBe('closed');

    $this->put(route('business-partners.open-items.update-due-date', [$fx['partner'], $openItem]), [
        'due_date' => '2026-03-15',
    ])->assertSessionHasErrors('due_date');

    expect($openItem->fresh()->due_date->format('Y-m-d'))->toBe('2026-02-04');
});

it('rechaza corregir el vencimiento de una partida de otro socio (404)', function () {
    $fx = businessPartnerHttpFixture();
    $otherPartner = BusinessPartner::factory()->create(['company_id' => $fx['company']->id, 'gl_account_id' => $fx['cxc']->id]);

    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0,
                businessPartnerId: $fx['partner']->id, dueDate: '2026-02-04', opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
    );

    $openItem = BpOpenItem::where('business_partner_id', $fx['partner']->id)->sole();

    $this->put(route('business-partners.open-items.update-due-date', [$otherPartner, $openItem]), [
        'due_date' => '2026-03-15',
    ])->assertNotFound();
});

it('reconcilia entre sí dos partidas del mismo socio cuyo neto da cero, desde la pantalla de partidas', function () {
    $fx = businessPartnerHttpFixture();

    // Factura (débito, abre partida) y un movimiento en sentido contrario
    // (crédito, abre partida) por el mismo monto — el caso real: un pago que
    // ya se había contabilizado directo contra la cuenta de control, con su
    // propia partida, en vez de aplicarse formalmente contra la primera.
    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0,
                businessPartnerId: $fx['partner']->id, dueDate: now()->addDays(30)->format('Y-m-d'), opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
    );
    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000,
                businessPartnerId: $fx['partner']->id, dueDate: now()->addDays(30)->format('Y-m-d'), opensItem: true,
            ),
        ],
    );

    $items = BpOpenItem::where('business_partner_id', $fx['partner']->id)->get();
    expect($items)->toHaveCount(2);

    $this->get(route('business-partners.open-items', $fx['partner']))
        ->assertInertia(fn ($page) => $page
            ->has('openItems', 2)
            ->where('openItems.0.signed_balance', fn ($v) => in_array($v, ['1000.00', '-1000.00'], true))
        );

    $this->post(route('business-partners.open-items.reconcile', $fx['partner']), [
        'open_item_ids' => $items->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    expect(BpOpenItem::whereIn('id', $items->pluck('id'))->get()->every(fn ($i) => $i->status === 'closed'))->toBeTrue();
});

it('rechaza reconciliar cuando el neto de las partidas seleccionadas no da cero', function () {
    $fx = businessPartnerHttpFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0,
                businessPartnerId: $fx['partner']->id, dueDate: now()->addDays(30)->format('Y-m-d'), opensItem: true,
            ),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
        ],
    );
    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], now(), now(),
        [
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 400, credit: 0),
            new JournalLineInput(
                $fx['cxc']->id, $fx['company']->local_currency_id, debit: 0, credit: 400,
                businessPartnerId: $fx['partner']->id, dueDate: now()->addDays(30)->format('Y-m-d'), opensItem: true,
            ),
        ],
    );

    $items = BpOpenItem::where('business_partner_id', $fx['partner']->id)->get();

    $this->post(route('business-partners.open-items.reconcile', $fx['partner']), [
        'open_item_ids' => $items->pluck('id')->all(),
    ])->assertSessionHasErrors('reconciliation');

    expect(BpOpenItem::whereIn('id', $items->pluck('id'))->get()->every(fn ($i) => $i->status !== 'closed'))->toBeTrue();
});

// --- Categoría y centro de costo (para reportes/análisis de ventas) -------

it('crea un socio con categoría y centro de costo asignados', function () {
    $fx = businessPartnerHttpFixture();
    $category = BpCategory::factory()->create(['company_id' => $fx['company']->id, 'code' => 'MAY']);
    $costCenter = CostCenter::factory()->create(['company_id' => $fx['company']->id, 'code' => '01']);

    $this->post(route('business-partners.store'), [
        'code' => 'C-200',
        'name' => 'Cliente categorizado',
        'type' => 'client',
        'category_id' => $category->id,
        'cost_center_id' => $costCenter->id,
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasNoErrors();

    $partner = BusinessPartner::where('code', 'C-200')->sole();
    expect($partner->category_id)->toBe($category->id)
        ->and($partner->cost_center_id)->toBe($costCenter->id);
});

it('crea un socio sin categoría ni centro de costo, quedan en null', function () {
    $fx = businessPartnerHttpFixture();

    $this->post(route('business-partners.store'), [
        'code' => 'C-201',
        'name' => 'Cliente sin categorizar',
        'type' => 'client',
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasNoErrors();

    $partner = BusinessPartner::where('code', 'C-201')->sole();
    expect($partner->category_id)->toBeNull()
        ->and($partner->cost_center_id)->toBeNull();
});

it('rechaza una categoría de otra compañía', function () {
    $fx = businessPartnerHttpFixture();
    $companyB = Company::factory()->create();
    $categoryB = BpCategory::factory()->create(['company_id' => $companyB->id]);

    $this->post(route('business-partners.store'), [
        'code' => 'C-202',
        'name' => 'Intento ajeno',
        'type' => 'client',
        'category_id' => $categoryB->id,
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasErrors('category_id');

    expect(BusinessPartner::where('code', 'C-202')->exists())->toBeFalse();
});

it('rechaza un centro de costo de otra compañía', function () {
    $fx = businessPartnerHttpFixture();
    $companyB = Company::factory()->create();
    $costCenterB = CostCenter::factory()->create(['company_id' => $companyB->id]);

    $this->post(route('business-partners.store'), [
        'code' => 'C-203',
        'name' => 'Intento ajeno',
        'type' => 'client',
        'cost_center_id' => $costCenterB->id,
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasErrors('cost_center_id');

    expect(BusinessPartner::where('code', 'C-203')->exists())->toBeFalse();
});

it('edita un socio para asignarle categoría y centro de costo', function () {
    $fx = businessPartnerHttpFixture();
    $category = BpCategory::factory()->create(['company_id' => $fx['company']->id, 'code' => 'MIN']);
    $costCenter = CostCenter::factory()->create(['company_id' => $fx['company']->id, 'code' => '02']);

    $this->put(route('business-partners.update', $fx['partner']->id), [
        'code' => $fx['partner']->code,
        'name' => $fx['partner']->name,
        'type' => $fx['partner']->type,
        'category_id' => $category->id,
        'cost_center_id' => $costCenter->id,
        'gl_account_id' => $fx['cxc']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasNoErrors();

    $partner = $fx['partner']->fresh();
    expect($partner->category_id)->toBe($category->id)
        ->and($partner->cost_center_id)->toBe($costCenter->id);
});

it('el formulario de creación trae las categorías y centros de costo disponibles', function () {
    $fx = businessPartnerHttpFixture();
    BpCategory::factory()->create(['company_id' => $fx['company']->id, 'code' => 'MAY']);
    CostCenter::factory()->create(['company_id' => $fx['company']->id, 'code' => '01']);

    $this->get(route('business-partners.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('BusinessPartners/Create')
            ->has('categories', 1)
            ->has('costCenters', 1)
        );
});

it('el índice expone la categoría y el centro de costo de cada socio', function () {
    $fx = businessPartnerHttpFixture();
    $category = BpCategory::factory()->create(['company_id' => $fx['company']->id, 'code' => 'MAY', 'name' => 'Mayorista']);
    $costCenter = CostCenter::factory()->create(['company_id' => $fx['company']->id, 'code' => '01', 'name' => 'Ventas Norte']);
    $fx['partner']->update(['category_id' => $category->id, 'cost_center_id' => $costCenter->id]);

    $this->get(route('business-partners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('BusinessPartners/Index')
            ->where('partners.0.category.name', 'Mayorista')
            ->where('partners.0.cost_center.name', 'Ventas Norte')
        );
});
