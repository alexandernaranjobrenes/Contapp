<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Reporting\Support\ReportCatalog;

function inventoryAgingHttpFixture(): array
{
    $f = movementFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);

    return $f;
}

it('la pantalla muestra la antigüedad con su resumen por tramo', function () {
    inventoryAgingHttpFixture();

    $this->get(route('reports.inventory-aging.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/InventoryAging')
            ->has('result.rows', 1)
            ->where('result.rows.0.item_code', 'ART-1')
            ->where('result.rows.0.never_issued', true)
            ->where('result.rows.0.days_idle', 0)
            ->where('result.total_value_local', fn ($v) => (float) $v === 1000.0)
            ->has('result.bucket_labels')
        );
});

it('acepta cortes personalizados por HTTP', function () {
    inventoryAgingHttpFixture();

    $this->get(route('reports.inventory-aging.index', ['buckets' => '10,20']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('result.buckets_input', '10,20'));
});

it('un corte mal formado no revienta el reporte', function () {
    inventoryAgingHttpFixture();

    $this->get(route('reports.inventory-aging.index', ['buckets' => 'xx']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('result.buckets_input', '30,60,90,180,360'));
});

it('rechaza un almacén de otra compañía como filtro', function () {
    inventoryAgingHttpFixture();

    $ajeno = Warehouse::factory()->create([
        'company_id' => App\Domains\Core\Models\Company::factory()->create()->id,
    ]);

    $this->get(route('reports.inventory-aging.index', ['warehouse_id' => $ajeno->id]))
        ->assertSessionHasErrors('warehouse_id');
});

it('exporta a XLSX', function () {
    inventoryAgingHttpFixture();

    $res = $this->get(route('reports.inventory-aging.export'));

    $res->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect(strlen($res->streamedContent()))->toBeGreaterThan(0);
});

it('exporta a PDF', function () {
    inventoryAgingHttpFixture();

    $this->get(route('reports.inventory-aging.export-pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('queda registrado en el catálogo de reportes guardables', function () {
    expect(ReportCatalog::definitions())->toHaveKey('inventory-aging')
        ->and(ReportCatalog::definitions()['inventory-aging']['parameters'])->toHaveKey('buckets');
});
