<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;

/**
 * Compañía facturable con su actividad económica, que es de donde salía la
 * cuenta de ingresos antes de que existiera esta determinación.
 */
function revenueFixture(): array
{
    $f = salesFixture();

    logInAsCompanyUser($f['company']);

    $f['salesType'] = DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FVE-R', 'origin_module' => 'ventas',
    ]);

    return $f;
}

function revenueAccount(array $f, string $code): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id,
        'code' => $code,
        'account_type' => 'income',
        'accepts_posting' => true,
        'is_active' => true,
    ]);
}

function determination(array $f, string $level, ?int $scopeId, string $category, int $accountId): void
{
    GlDetermination::factory()->create([
        'company_id' => $f['company']->id,
        'scope_level' => $level,
        'scope_id' => $scopeId,
        'category' => $category,
        'account_id' => $accountId,
    ]);
}

/**
 * La línea de mercancía base, para poder agregarle otras.
 */
function goodsLine(array $f): array
{
    return [
        'cabys_code' => '2310110000000',
        'description' => 'Producto de prueba',
        'unit_code' => 'Unid',
        'quantity' => 10,
        'unit_price' => 1000,
        'item_id' => $f['item']->id,
        'warehouse_id' => $f['warehouse']->id,
        'item_code' => 'ART-1',
        'is_service' => false,
        'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
    ];
}

function sell(array $f, array $overrides = [])
{
    return test()->post(route('sales-documents.store'), array_merge([
        'document_type_id' => $f['salesType']->id,
        'fiscal_document_type' => '01',
        'business_partner_id' => $f['customer']->id,
        'currency_id' => $f['company']->local_currency_id,
        'exchange_rate' => 1,
        'sale_condition' => '02',
        'credit_term_days' => 30,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [goodsLine($f)],
    ], $overrides));
}

/**
 * Lo acreditado a cada cuenta de ingreso del último asiento.
 *
 * @return array<int, float>
 */
function revenueCredits(): array
{
    $document = SalesDocument::latest('id')->firstOrFail();

    return JournalDetail::where('journal_entry_id', $document->journal_entry_id)
        ->whereHas('account', fn ($q) => $q->where('account_type', 'income'))
        ->get()
        ->groupBy('account_id')
        ->map(fn ($rows) => (float) $rows->sum('credit_local'))
        ->all();
}

// ── Sin configurar nada: exactamente como antes ──────────────────────────

it('SIN REGRESIÓN: sin determinación, el ingreso va a la cuenta de la actividad económica', function () {
    $f = revenueFixture();

    sell($f)->assertSessionHasNoErrors();

    // Una sola línea de ingreso, a la cuenta de siempre: una compañía que no
    // configure nada contabiliza igual que antes de esta función.
    $credits = revenueCredits();

    expect($credits)->toHaveCount(1)
        ->and($credits)->toHaveKey($f['activity']->revenue_account_id)
        ->and($credits[$f['activity']->revenue_account_id])->toBe(10000.0);
});

// ── La escalera, escalón por escalón ─────────────────────────────────────

it('la regla de COMPAÑÍA le gana a la actividad económica', function () {
    $f = revenueFixture();
    $cuenta = revenueAccount($f, '4-01-01-01-100');

    determination($f, 'company', null, 'sales_revenue', $cuenta->id);

    sell($f)->assertSessionHasNoErrors();

    expect(revenueCredits())->toBe([$cuenta->id => 10000.0]);
});

it('la regla de ALMACÉN le gana a la de compañía', function () {
    $f = revenueFixture();
    $empresa = revenueAccount($f, '4-01-01-01-100');
    $almacen = revenueAccount($f, '4-01-01-01-200');

    determination($f, 'company', null, 'sales_revenue', $empresa->id);
    determination($f, 'warehouse', $f['warehouse']->id, 'sales_revenue', $almacen->id);

    sell($f)->assertSessionHasNoErrors();

    expect(revenueCredits())->toBe([$almacen->id => 10000.0]);
});

it('la regla de GRUPO le gana a la de almacén', function () {
    $f = revenueFixture();
    $almacen = revenueAccount($f, '4-01-01-01-200');
    $grupo = revenueAccount($f, '4-01-01-01-300');

    $group = ItemGroup::factory()->create(['company_id' => $f['company']->id]);
    $f['item']->update(['item_group_id' => $group->id]);

    determination($f, 'warehouse', $f['warehouse']->id, 'sales_revenue', $almacen->id);
    determination($f, 'item_group', $group->id, 'sales_revenue', $grupo->id);

    sell($f)->assertSessionHasNoErrors();

    expect(revenueCredits())->toBe([$grupo->id => 10000.0]);
});

it('LA PRUEBA DE LA ESCALERA: la regla del ARTÍCULO le gana a todas', function () {
    $f = revenueFixture();
    $empresa = revenueAccount($f, '4-01-01-01-100');
    $almacen = revenueAccount($f, '4-01-01-01-200');
    $grupo = revenueAccount($f, '4-01-01-01-300');
    $articulo = revenueAccount($f, '4-01-01-01-400');

    $group = ItemGroup::factory()->create(['company_id' => $f['company']->id]);
    $f['item']->update(['item_group_id' => $group->id]);

    // Los cuatro escalones configurados a la vez: gana el más específico.
    determination($f, 'company', null, 'sales_revenue', $empresa->id);
    determination($f, 'warehouse', $f['warehouse']->id, 'sales_revenue', $almacen->id);
    determination($f, 'item_group', $group->id, 'sales_revenue', $grupo->id);
    determination($f, 'item', $f['item']->id, 'sales_revenue', $articulo->id);

    sell($f)->assertSessionHasNoErrors();

    expect(revenueCredits())->toBe([$articulo->id => 10000.0]);
});

// ── Mercancía y servicio se separan ──────────────────────────────────────

it('LA PRUEBA DE LAS DOS CATEGORÍAS: mercancía y servicio van a cuentas distintas', function () {
    $f = revenueFixture();
    $mercancia = revenueAccount($f, '4-01-01-01-100');
    $servicios = revenueAccount($f, '4-01-02-01-100');

    determination($f, 'company', null, 'sales_revenue', $mercancia->id);
    determination($f, 'company', null, 'service_revenue', $servicios->id);

    $lines = [goodsLine($f), [
        'cabys_code' => '8511010000000',
        'description' => 'Instalación a domicilio',
        'unit_code' => 'Sp',
        'quantity' => 1,
        'unit_price' => 5000,
        'is_service' => true,
        'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
    ]];

    sell($f, ['lines' => $lines])->assertSessionHasNoErrors();

    $credits = revenueCredits();

    expect($credits)->toHaveCount(2)
        ->and($credits[$mercancia->id])->toBe(10000.0)
        ->and($credits[$servicios->id])->toBe(5000.0);
});

it('dos artículos con cuentas distintas producen dos líneas de ingreso', function () {
    $f = revenueFixture();
    $cuentaA = revenueAccount($f, '4-01-01-01-100');
    $cuentaB = revenueAccount($f, '4-01-01-01-200');

    $otro = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-2', 'uom_id' => $f['item']->uom_id,
    ]);

    determination($f, 'item', $f['item']->id, 'sales_revenue', $cuentaA->id);
    // La segunda línea va como servicio para no exigir existencia del
    // segundo artículo: lo que se prueba es el reparto del ingreso.
    determination($f, 'item', $otro->id, 'service_revenue', $cuentaB->id);

    $lines = [goodsLine($f), [
        'cabys_code' => '8511010000000',
        'description' => 'Segundo artículo',
        'unit_code' => 'Sp',
        'quantity' => 2,
        'unit_price' => 2500,
        'item_id' => $otro->id,
        'item_code' => 'ART-2',
        'is_service' => true,
        'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
    ]];

    sell($f, ['lines' => $lines])->assertSessionHasNoErrors();

    $credits = revenueCredits();

    expect($credits[$cuentaA->id])->toBe(10000.0)
        ->and($credits[$cuentaB->id])->toBe(5000.0);
});

// ── El asiento sigue cuadrando ───────────────────────────────────────────

it('EL INVARIANTE: repartir el ingreso en varias cuentas no descuadra el asiento', function () {
    $f = revenueFixture();
    $cuentaA = revenueAccount($f, '4-01-01-01-100');
    $cuentaB = revenueAccount($f, '4-01-02-01-100');

    determination($f, 'company', null, 'sales_revenue', $cuentaA->id);
    determination($f, 'company', null, 'service_revenue', $cuentaB->id);

    // Un precio con decimales para forzar residuo de redondeo entre las dos
    // cuentas.
    $lines = [goodsLine($f), [
        'cabys_code' => '8511010000000',
        'description' => 'Servicio con decimales',
        'unit_code' => 'Sp',
        'quantity' => 3,
        'unit_price' => 333.33,
        'is_service' => true,
        'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
    ]];

    sell($f, ['lines' => $lines])->assertSessionHasNoErrors();

    $document = SalesDocument::latest('id')->firstOrFail();

    $debits = JournalDetail::where('journal_entry_id', $document->journal_entry_id)->sum('debit_local');
    $credits = JournalDetail::where('journal_entry_id', $document->journal_entry_id)->sum('credit_local');

    // PostJournalService ya rechazaría un asiento descuadrado, pero esta
    // prueba deja explícito que el reparto no pierde ni gana céntimos.
    expect((float) $debits)->toBe((float) $credits)
        ->and((float) $debits)->toBeGreaterThan(0);
});

// ── La ficha escribe la misma tabla que la matriz ────────────────────────

it('LA PRUEBA DE LA FICHA: guardar la cuenta desde el artículo crea la regla', function () {
    $f = revenueFixture();
    $cuenta = revenueAccount($f, '4-01-01-01-100');

    $this->put(route('items.update', $f['item']->id), [
        'name' => $f['item']->name,
        'uom_id' => $f['item']->uom_id,
        'is_inventory_item' => true,
        'status' => 'active',
        'accounts' => ['sales_revenue' => $cuenta->id],
    ])->assertSessionHasNoErrors();

    // La ficha y la matriz son dos puertas a la misma tabla.
    $rule = GlDetermination::where('company_id', $f['company']->id)
        ->where('scope_level', 'item')
        ->where('scope_id', $f['item']->id)
        ->where('category', 'sales_revenue')
        ->sole();

    expect($rule->account_id)->toBe($cuenta->id);

    // Y la venta la respeta.
    sell($f)->assertSessionHasNoErrors();

    expect(revenueCredits())->toBe([$cuenta->id => 10000.0]);
});

it('vaciar el campo en la ficha BORRA la regla y el artículo vuelve a heredar', function () {
    $f = revenueFixture();
    $propia = revenueAccount($f, '4-01-01-01-400');
    $empresa = revenueAccount($f, '4-01-01-01-100');

    determination($f, 'company', null, 'sales_revenue', $empresa->id);
    determination($f, 'item', $f['item']->id, 'sales_revenue', $propia->id);

    $this->put(route('items.update', $f['item']->id), [
        'name' => $f['item']->name,
        'uom_id' => $f['item']->uom_id,
        'is_inventory_item' => true,
        'status' => 'active',
        'accounts' => ['sales_revenue' => null],
    ])->assertSessionHasNoErrors();

    expect(GlDetermination::where('scope_level', 'item')->where('scope_id', $f['item']->id)->count())->toBe(0);

    sell($f)->assertSessionHasNoErrors();

    // Vuelve a la de compañía: vaciar el campo es la forma de decir
    // "heredá del nivel de arriba".
    expect(revenueCredits())->toBe([$empresa->id => 10000.0]);
});

it('la ficha rechaza una cuenta que no acepta movimientos', function () {
    $f = revenueFixture();

    $resumen = ChartOfAccount::factory()->create([
        'company_id' => $f['company']->id,
        'accepts_posting' => false,
        'is_active' => true,
    ]);

    $this->put(route('items.update', $f['item']->id), [
        'name' => $f['item']->name,
        'uom_id' => $f['item']->uom_id,
        'is_inventory_item' => true,
        'status' => 'active',
        'accounts' => ['sales_revenue' => $resumen->id],
    ])->assertSessionHasErrors('accounts.sales_revenue');
});

it('borrar el artículo se lleva sus reglas, para que no queden huérfanas', function () {
    $f = revenueFixture();
    $cuenta = revenueAccount($f, '4-01-01-01-100');

    $otro = Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-BORRAR', 'uom_id' => $f['item']->uom_id,
    ]);

    determination($f, 'item', $otro->id, 'sales_revenue', $cuenta->id);

    $this->delete(route('items.destroy', $otro->id))->assertSessionHasNoErrors();

    // Sin esto la matriz las mostraría como "(eliminado)" y no habría forma
    // de limpiarlas desde ninguna pantalla.
    expect(GlDetermination::where('scope_level', 'item')->where('scope_id', $otro->id)->count())->toBe(0);
});
