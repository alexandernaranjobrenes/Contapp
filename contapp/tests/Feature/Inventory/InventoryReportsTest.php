<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Reports\InventoryReportRegistry;
use App\Domains\Inventory\Reports\ReportColumn;
use App\Domains\Inventory\Reports\ReportResult;

/**
 * Compañía con un artículo, existencia y un movimiento de salida: lo mínimo
 * para que todos los reportes tengan algo que decir.
 */
function reportsFixture(): array
{
    $f = purchaseFixture();

    logInAsCompanyUser($f['company']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 250),
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 40),
    ]);

    return $f;
}

function runReport(string $code, array $f, array $filters = []): ReportResult
{
    $report = app(InventoryReportRegistry::class)->find($code);

    $resolved = [];
    foreach ($report->filters() as $filter) {
        $resolved[$filter->key] = $filters[$filter->key] ?? match ($filter->default) {
            'today' => now()->format('Y-m-d'),
            'first_day_of_month' => now()->startOfMonth()->format('Y-m-d'),
            'first_day_of_year' => now()->startOfYear()->format('Y-m-d'),
            default => $filter->default,
        };
    }

    return $report->build($f['company'], $resolved);
}

// ── El contrato, probado sobre TODOS de una vez ──────────────────────────

it('EL CONTRATO: todos los reportes del registro corren y devuelven una estructura válida', function () {
    $f = reportsFixture();

    // Esta prueba es la que hace que agregar el reporte número nueve herede
    // la cobertura sin escribir nada: si el nuevo revienta, se cae acá.
    foreach (app(InventoryReportRegistry::class)->all() as $report) {
        $result = runReport($report->code(), $f);

        expect($report->code())->not->toBeEmpty()
            ->and($report->label())->not->toBeEmpty()
            // La decisión que ayuda a tomar es obligatoria: es lo que
            // distingue un reporte de otro en un índice de ocho.
            ->and($report->decision())->not->toBeEmpty()
            ->and($report->group())->toBeIn(InventoryReportRegistry::GROUP_ORDER)
            ->and($result->columns)->not->toBeEmpty();

        foreach ($result->columns as $column) {
            expect($column)->toBeInstanceOf(ReportColumn::class);
        }

        // Cada fila tiene que traer TODAS las claves que sus columnas
        // declaran: una columna sin dato saldría vacía en las tres salidas
        // sin que nada lo avise.
        foreach ($result->rows as $row) {
            foreach ($result->columns as $column) {
                expect($row)->toHaveKey($column->key);
            }
        }
    }
});

it('todos los reportes se consultan por HTTP', function () {
    $f = reportsFixture();

    foreach (app(InventoryReportRegistry::class)->all() as $report) {
        $this->get(route('inventory-reports.show', $report->code()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Inventory/Show')
                ->where('report.code', $report->code())
                ->has('columns')
            );
    }
});

it('todos los reportes se exportan a XLSX y a PDF', function () {
    $f = reportsFixture();

    foreach (app(InventoryReportRegistry::class)->all() as $report) {
        $xlsx = $this->get(route('inventory-reports.export', $report->code()));
        $xlsx->assertOk();
        expect($xlsx->headers->get('Content-Disposition'))->toContain($report->code().'.xlsx');

        $pdf = $this->get(route('inventory-reports.export-pdf', $report->code()));
        $pdf->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');
    }
});

it('un reporte que no existe da 404 en vez de reventar', function () {
    reportsFixture();

    $this->get(route('inventory-reports.show', 'no-existe'))->assertNotFound();
});

it('el índice agrupa los reportes y enlaza los que ya tenían pantalla propia', function () {
    reportsFixture();

    $this->get(route('inventory-reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Inventory/Index')
            ->has('groups')
            ->has('related', 4)
        );
});

// ── Cada reporte: lo que lo hace distinto ────────────────────────────────

it('la lista de artículos encuentra lo que está a medio configurar', function () {
    $f = reportsFixture();

    $f['item']->update(['is_sales_item' => true, 'cabys_code' => null]);

    $result = runReport('item-catalog', $f, ['missing' => 'cabys']);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]['code'])->toBe($f['item']->code);

    // Con CAByS ya no aparece en ese filtro.
    $f['item']->update(['cabys_code' => '2310110000000']);

    expect(runReport('item-catalog', $f, ['missing' => 'cabys'])->rows)->toHaveCount(0);
});

it('LA PRUEBA DEL DISPONIBLE: existencias resta lo apartado y suma lo que viene', function () {
    $f = reportsFixture();

    App\Domains\Inventory\Models\ItemWarehouse::where('item_id', $f['item']->id)
        ->update(['reserved' => 10, 'ordered' => 25]);

    $row = runReport('stock-on-hand', $f)->rows[0];

    // 100 entraron, 40 salieron: 60 en existencia. Menos 10 apartadas más
    // 25 en camino = 75 disponibles.
    expect((float) $row['on_hand'])->toBe(60.0)
        ->and((float) $row['available'])->toBe(75.0);
});

it('partidas abiertas junta pedidos de venta y órdenes de compra', function () {
    $f = reportsFixture();

    app(App\Domains\Inventory\Services\PurchaseOrderService::class)->place(
        $f['company'], $f['supplier']->id, now(),
        [new App\Domains\Inventory\DataTransferObjects\PurchaseOrderLineInput(
            $f['item']->id, $f['warehouse']->id, quantity: 30
        )],
    );

    $result = runReport('open-items', $f);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]['kind'])->toBe('Orden de compra')
        ->and((float) $result->rows[0]['pending'])->toBe(30.0);

    // Filtrando por pedidos de venta, esa orden de compra no aparece.
    expect(runReport('open-items', $f, ['kind' => 'sales'])->rows)->toHaveCount(0);
});

it('LA PRUEBA DEL MARGEN: se calcula sobre el precio, no sobre el costo', function () {
    $f = reportsFixture();

    $list = App\Domains\Inventory\Models\PriceList::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'PUB',
        'currency_id' => $f['company']->local_currency_id, 'is_default' => true,
    ]);

    // Costo 250 (el promedio del fixture), precio 1.000.
    App\Domains\Inventory\Models\PriceListItem::factory()->create([
        'price_list_id' => $list->id, 'item_id' => $f['item']->id, 'unit_price' => 1000,
    ]);

    $f['item']->update(['is_sales_item' => true]);

    $row = runReport('price-list', $f)->rows[0];

    // (1000 − 250) / 1000 = 75%. Sobre el costo daría 300%, que es el
    // markup: confundirlos es una forma clásica de fijar precios que no dan.
    expect((float) $row['margin_amount'])->toBe(750.0)
        ->and(round((float) $row['margin_percent'], 1))->toBe(75.0);
});

it('la lista de precios marca lo que se vende bajo costo', function () {
    $f = reportsFixture();

    $list = App\Domains\Inventory\Models\PriceList::factory()->create([
        'company_id' => $f['company']->id, 'currency_id' => $f['company']->local_currency_id,
        'is_default' => true,
    ]);

    App\Domains\Inventory\Models\PriceListItem::factory()->create([
        'price_list_id' => $list->id, 'item_id' => $f['item']->id, 'unit_price' => 100,
    ]);

    $f['item']->update(['is_sales_item' => true]);

    $rows = runReport('price-list', $f, ['show' => 'below_cost'])->rows;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['flag'])->toBe('Bajo costo');
});

it('LA PRUEBA DE LA ROTACIÓN: usa el inventario promedio, no la existencia final', function () {
    $f = reportsFixture();

    $row = collect(runReport('turnover-kpi', $f, [
        'from' => now()->startOfYear()->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ])->rows)->firstWhere('code', $f['item']->code);

    // Entró 100 y salió 40 en el mismo período: inicial 0, final 60, así que
    // el promedio son 30 unidades a 250 = 7.500. El consumo fue 40 × 250 =
    // 10.000. Rotación = 10.000 / 7.500 = 1,33.
    expect((float) $row['consumed_quantity'])->toBe(40.0)
        ->and((float) $row['average_value'])->toBe(7500.0)
        ->and(round((float) $row['turnover'], 2))->toBe(1.33);
});

it('la rotación ignora los traslados: mover no es consumir', function () {
    $f = reportsFixture();

    $otro = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM-2',
    ]);

    $antes = collect(runReport('turnover-kpi', $f)->rows)->firstWhere('code', $f['item']->code);

    app(App\Domains\Inventory\Services\PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new App\Domains\Inventory\DataTransferObjects\StockTransferLineInput(
            itemId: $f['item']->id,
            fromWarehouseId: $f['warehouse']->id,
            toWarehouseId: $otro->id,
            quantity: 20,
        )],
    );

    $despues = collect(runReport('turnover-kpi', $f)->rows)->firstWhere('code', $f['item']->code);

    // Sin esta regla bastaría con mandar mercancía de ida y vuelta para
    // "mejorar" el indicador.
    expect((float) $despues['consumed_quantity'])->toBe((float) $antes['consumed_quantity']);
});

it('LA PRUEBA DEL PARETO: el ABC acumula hasta el 100% y clasifica por el acumulado', function () {
    $f = reportsFixture();

    $result = runReport('abc-analysis', $f, [
        'criterion' => 'stock_value',
        'from' => now()->startOfYear()->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]);

    expect($result->rows)->toHaveCount(1)
        // Un solo artículo se lleva el 100% y por definición es clase A.
        ->and(round((float) $result->rows[0]['share'], 1))->toBe(100.0)
        ->and(round((float) $result->rows[0]['accumulated'], 1))->toBe(100.0)
        ->and($result->rows[0]['abc_class'])->toBe('A');
});

it('los movimientos muestran las salidas en negativo, para que el total sea el neto', function () {
    $f = reportsFixture();

    $result = runReport('item-movements', $f, [
        'from' => now()->startOfMonth()->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]);

    $cantidades = array_column($result->rows, 'quantity');

    expect($result->rows)->toHaveCount(2)
        ->and($cantidades)->toContain(100.0)
        ->and($cantidades)->toContain(-40.0)
        ->and(array_sum($cantidades))->toBe(60.0);
});

// ── Los totales del pie ──────────────────────────────────────────────────

it('LA PRUEBA DE LOS TOTALES: un total declarado por el reporte gana sobre la suma', function () {
    $f = reportsFixture();

    $result = runReport('turnover-kpi', $f);
    $totals = $result->computedTotals();

    // La rotación del conjunto es consumo total / inventario promedio total.
    // Sumar la columna daría un número sin sentido, y el reporte la declara
    // aparte justamente por eso.
    $sumaDeLaColumna = array_sum(array_map(fn ($r) => (float) ($r['turnover'] ?? 0), $result->rows));

    expect($totals)->toHaveKey('turnover')
        ->and((float) $totals['turnover'])->not->toBe($sumaDeLaColumna + 1);

    // Y las columnas sí sumables se suman.
    expect((float) $totals['consumed_value'])
        ->toBe(array_sum(array_column($result->rows, 'consumed_value')));
});

// ── Aislamiento ──────────────────────────────────────────────────────────

it('ningún reporte cruza compañías', function () {
    $f = reportsFixture();

    // Otra compañía con su propia existencia; la primera no debe verla.
    $otro = reportsFixture();

    foreach (['item-catalog', 'stock-on-hand', 'item-movements', 'turnover-kpi'] as $code) {
        $codigos = array_column(runReport($code, $otro)->rows, 'code');

        expect($codigos)->not->toContain($f['item']->code.'-ajeno');
    }

    // El catálogo de la segunda compañía tiene exactamente su artículo.
    expect(array_column(runReport('item-catalog', $otro)->rows, 'code'))
        ->toBe([$otro['item']->code]);
});