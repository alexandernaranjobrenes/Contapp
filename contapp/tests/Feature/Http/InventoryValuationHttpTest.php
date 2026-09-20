<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Reporting\Support\ReportCatalog;

function valuationFixture(): array
{
    $f = movementFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    return $f;
}

it('la pantalla muestra las existencias valorizadas al corte', function () {
    $f = valuationFixture();

    $this->get(route('reports.inventory-valuation.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/InventoryValuation')
            ->has('result.rows', 1)
            ->where('result.rows.0.item_code', 'ART-1')
            ->where('result.rows.0.value_local', fn ($v) => (float) $v === 10000.0)
            ->where('result.total_value_local', fn ($v) => (float) $v === 10000.0)
        );
});

it('el corte retroactivo se respeta por HTTP', function () {
    $f = valuationFixture();

    $this->get(route('reports.inventory-valuation.index', ['as_of' => now()->subDay()->format('Y-m-d')]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('result.rows', 0));
});

it('filtra por almacén desde la pantalla', function () {
    $f = valuationFixture();

    $otro = Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM2', 'status' => 'active',
    ]);

    $this->get(route('reports.inventory-valuation.index', ['warehouse_id' => $otro->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('result.rows', 0));
});

it('rechaza un almacén de otra compañía como filtro', function () {
    valuationFixture();

    $ajeno = Warehouse::factory()->create([
        'company_id' => App\Domains\Core\Models\Company::factory()->create()->id,
    ]);

    $this->get(route('reports.inventory-valuation.index', ['warehouse_id' => $ajeno->id]))
        ->assertSessionHasErrors('warehouse_id');
});

it('exporta a XLSX', function () {
    valuationFixture();

    $res = $this->get(route('reports.inventory-valuation.export'));

    $res->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect(strlen($res->streamedContent()))->toBeGreaterThan(0);
});

it('exporta a PDF', function () {
    valuationFixture();

    $this->get(route('reports.inventory-valuation.export-pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('queda registrado en el catálogo de reportes guardables', function () {
    expect(ReportCatalog::definitions())->toHaveKey('inventory-valuation')
        ->and(ReportCatalog::definitions()['inventory-valuation']['parameters'])->toHaveKey('as_of');
});
