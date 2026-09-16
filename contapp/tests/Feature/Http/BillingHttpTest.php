<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Billing\Models\BillingPaymentAccount;
use App\Domains\Billing\Models\BillingTaxAccount;
use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Models\User;

/**
 * salesFixture() arma la compañía completa; acá solo se agrega la sesión y un
 * tipo de documento del módulo "ventas", que es el que el controlador exige.
 */
function billingHttpFixture(): array
{
    $f = salesFixture();

    logInAsCompanyUser($f['company']);

    $f['salesType'] = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FVE-H', 'origin_module' => 'ventas',
    ]);

    return $f;
}

function salePayload(array $f, array $overrides = []): array
{
    return array_merge([
        'document_type_id' => $f['salesType']->id,
        'fiscal_document_type' => '01',
        'business_partner_id' => $f['customer']->id,
        'currency_id' => $f['company']->local_currency_id,
        'exchange_rate' => 1,
        'sale_condition' => '02',
        'credit_term_days' => 30,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'cabys_code' => '2310110000000',
            'description' => 'Producto de prueba',
            'unit_code' => 'Unid',
            'quantity' => 10,
            'unit_price' => 2500,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'item_code' => 'ART-1',
            'is_service' => false,
            'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
        ]],
    ], $overrides);
}

it('emite una factura por HTTP y redirige a su detalle', function () {
    $f = billingHttpFixture();

    $this->post(route('sales-documents.store'), salePayload($f))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $document = SalesDocument::where('company_id', $f['company']->id)->sole();

    expect((float) $document->total_document)->toBe(28250.0)
        ->and($document->journal_entry_id)->not->toBeNull()
        ->and($document->inventory_document_id)->not->toBeNull()
        ->and($document->status)->toBe('posted');
});

it('rechaza un tipo de documento que no es del módulo de ventas', function () {
    $f = billingHttpFixture();

    $payload = salePayload($f);
    $payload['document_type_id'] = $f['documentType']->id; // origin_module = inventario

    $this->post(route('sales-documents.store'), $payload)->assertSessionHasErrors('document_type_id');
});

it('rechaza un CAByS que no tiene 13 dígitos', function () {
    $f = billingHttpFixture();

    $payload = salePayload($f);
    $payload['lines'][0]['cabys_code'] = '123';

    $this->post(route('sales-documents.store'), $payload)->assertSessionHasErrors('lines.0.cabys_code');
});

it('rechaza una unidad de medida que no está en el catálogo de la norma', function () {
    $f = billingHttpFixture();

    $payload = salePayload($f);
    $payload['lines'][0]['unit_code'] = 'XYZ';

    $this->post(route('sales-documents.store'), $payload)->assertSessionHasErrors('lines.0.unit_code');
});

it('traduce la falta de existencia a un error de formulario sin dejar comprobante', function () {
    $f = billingHttpFixture();

    $payload = salePayload($f);
    $payload['lines'][0]['quantity'] = 500;

    $this->post(route('sales-documents.store'), $payload)->assertSessionHasErrors('billing');

    expect(SalesDocument::count())->toBe(0);
});

it('rechaza más de cuatro medios de pago', function () {
    $f = billingHttpFixture();

    $payload = salePayload($f, [
        'sale_condition' => '01',
        'credit_term_days' => null,
        'payments' => array_fill(0, 5, ['method_code' => '01', 'amount' => 5650]),
    ]);

    $this->post(route('sales-documents.store'), $payload)->assertSessionHasErrors('payments');
});

it('la pantalla de emisión expone los catálogos de la norma y el estado de Hacienda', function () {
    $f = billingHttpFixture();

    $this->get(route('sales-documents.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/Sales/Create')
            ->where('catalogs.ivaRates.08.percentage', '13.00')
            ->has('catalogs.saleConditions')
            ->has('catalogs.units')
            ->where('hacienda.signer_configured', false)
            ->where('hacienda.transport_configured', false)
        );
});

it('el detalle muestra las líneas con sus impuestos y el enlace al asiento', function () {
    $f = billingHttpFixture();
    $this->post(route('sales-documents.store'), salePayload($f));

    $document = SalesDocument::where('company_id', $f['company']->id)->sole();

    $this->get(route('sales-documents.show', $document->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/Sales/Show')
            ->has('document.lines', 1)
            ->has('document.lines.0.taxes', 1)
            ->where('document.journal_entry_id', $document->journal_entry_id)
        );
});

it('descarga el XML del comprobante emitido', function () {
    $f = billingHttpFixture();
    $this->post(route('sales-documents.store'), salePayload($f));

    $document = SalesDocument::where('company_id', $f['company']->id)->sole();

    $response = $this->get(route('sales-documents.xml', $document->id));

    $response->assertOk()->assertHeader('content-type', 'application/xml');

    expect($response->streamedContent())->toContain('<FacturaElectronica')
        ->and($response->streamedContent())->toContain($document->clave);
});

it('solo lista los comprobantes de la compañía activa', function () {
    $f = billingHttpFixture();
    $this->post(route('sales-documents.store'), salePayload($f));

    $this->get(route('sales-documents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Billing/Sales/Index')->has('documents', 1));
});

// --- Configuración ---

it('registra una actividad económica y rechaza el código duplicado', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cuenta = ChartOfAccount::factory()->create(['company_id' => $company->id]);

    $payload = ['code' => '620100', 'name' => 'Programación', 'revenue_account_id' => $cuenta->id, 'is_default' => true];

    $this->post(route('billing-settings.activities.store'), $payload)->assertSessionHasNoErrors();
    $this->post(route('billing-settings.activities.store'), $payload)->assertSessionHasErrors('code');

    expect(CompanyEconomicActivity::where('company_id', $company->id)->count())->toBe(1);
});

it('marcar una actividad por defecto desmarca la anterior', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cuenta = ChartOfAccount::factory()->create(['company_id' => $company->id]);

    $this->post(route('billing-settings.activities.store'), [
        'code' => '620100', 'name' => 'Primera', 'revenue_account_id' => $cuenta->id, 'is_default' => true,
    ]);

    $this->post(route('billing-settings.activities.store'), [
        'code' => '620200', 'name' => 'Segunda', 'revenue_account_id' => $cuenta->id, 'is_default' => true,
    ]);

    $porDefecto = CompanyEconomicActivity::where('company_id', $company->id)->where('is_default', true)->get();

    expect($porDefecto)->toHaveCount(1)
        ->and($porDefecto->first()->code)->toBe('620200');
});

it('rechaza configurar una cuenta que no acepta movimientos', function () {
    ['company' => $company] = logInAsCompanyUser();
    $noPostea = ChartOfAccount::factory()->nonPosting()->create(['company_id' => $company->id]);

    $this->post(route('billing-settings.tax-accounts.store'), [
        'iva_rate_code' => '08', 'account_id' => $noPostea->id,
    ])->assertSessionHasErrors('account_id');
});

it('rechaza duplicar la cuenta de un medio de pago', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cuenta = ChartOfAccount::factory()->create(['company_id' => $company->id]);

    $payload = ['method_code' => '01', 'account_id' => $cuenta->id];

    $this->post(route('billing-settings.payment-accounts.store'), $payload)->assertSessionHasNoErrors();
    $this->post(route('billing-settings.payment-accounts.store'), $payload)->assertSessionHasErrors('method_code');

    expect(BillingPaymentAccount::where('company_id', $company->id)->count())->toBe(1);
});

it('la pantalla de configuración lista las tres piezas que la venta necesita', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cuenta = ChartOfAccount::factory()->create(['company_id' => $company->id]);

    CompanyEconomicActivity::create([
        'company_id' => $company->id, 'code' => '620100', 'name' => 'Actividad',
        'revenue_account_id' => $cuenta->id, 'is_default' => true,
    ]);
    BillingTaxAccount::create(['company_id' => $company->id, 'iva_rate_code' => '08', 'account_id' => $cuenta->id]);
    BillingPaymentAccount::create(['company_id' => $company->id, 'method_code' => '01', 'account_id' => $cuenta->id]);

    $this->get(route('billing-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/Settings/Index')
            ->has('activities', 1)
            ->has('taxAccounts', 1)
            ->has('paymentAccounts', 1)
        );
});

it('bloquea el módulo a un usuario sin permiso de facturación', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    $this->actingAs($user);

    $this->get(route('sales-documents.index'))->assertForbidden();
    $this->get(route('billing-settings.index'))->assertForbidden();
});
