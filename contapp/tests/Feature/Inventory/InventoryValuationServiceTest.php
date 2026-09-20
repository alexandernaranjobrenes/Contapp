<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\InventoryValuationService;

function valuation(array $f, string $asOf, ?int $warehouseId = null, ?int $groupId = null, bool $hideZero = true)
{
    return app(InventoryValuationService::class)
        ->build($f['company'], $asOf, $warehouseId, $groupId, $hideZero);
}

/**
 * Saldo contable de la cuenta de inventario en una fecha. 'voided' cuenta
 * igual que 'posted' porque su reversión es el espejo — mismo criterio que
 * TrialBalanceService.
 */
function inventoryGlBalance(array $f, string $asOf): string
{
    $rows = JournalDetail::where('account_id', $f['accounts']['inventory']->id)
        ->whereHas('journalEntry', fn ($q) => $q
            ->where('company_id', $f['company']->id)
            ->whereIn('status', ['posted', 'voided'])
            ->whereDate('posting_date', '<=', $asOf))
        ->get();

    return number_format(
        (float) $rows->sum('debit_local') - (float) $rows->sum('credit_local'),
        2, '.', ''
    );
}

it('valúa la existencia a la fecha de corte', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    $r = valuation($f, now()->format('Y-m-d'));

    expect($r->rows)->toHaveCount(1)
        ->and((float) $r->rows[0]->quantity)->toBe(10.0)
        ->and((float) $r->rows[0]->valueLocal)->toBe(10000.0)
        ->and((float) $r->totalValueLocal)->toBe(10000.0);
});

it('LA PRUEBA CENTRAL: el total del reporte es igual al saldo contable de inventario', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 5, unitCostLocal: 1400),
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 6),
    ]);

    $hoy = now()->format('Y-m-d');

    // Si estos dos números no coinciden, el reporte no sirve para lo único
    // que existe: demostrar que el inventario cuadra con la contabilidad.
    expect(valuation($f, $hoy)->totalValueLocal)->toBe(inventoryGlBalance($f, $hoy));
});

it('el corte retroactivo ignora los movimientos posteriores', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    $ayer = now()->subDay()->format('Y-m-d');

    // El movimiento es de hoy: al corte de ayer el inventario estaba en cero.
    expect(valuation($f, $ayer)->rows)->toHaveCount(0)
        ->and((float) valuation($f, $ayer)->totalValueLocal)->toBe(0.0);
});

it('NO usa el costo promedio de hoy para valuar una fecha pasada', function () {
    $f = inventoryFixture();

    // Día 1: 10 u a 1.000 → promedio 1.000, valor 10.000.
    $this->travelTo(now()->subDays(3));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);
    $corte = now()->format('Y-m-d');

    // Día 4: entran 10 u a 3.000 → el promedio de HOY sube a 2.000.
    $this->travelBack();
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 3000),
    ]);

    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(2000.0);

    $r = valuation($f, $corte);

    // Valuar con el promedio de hoy daría 20.000. El correcto es 10.000.
    expect((float) $r->rows[0]->valueLocal)->toBe(10000.0)
        ->and((float) $r->rows[0]->unitCostLocal)->toBe(1000.0);
});

it('el costo unitario es derivado del corte, no leído del artículo', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 2000),
    ]);

    $r = valuation($f, now()->format('Y-m-d'));

    // 20 u por 30.000 = 1.500 la unidad.
    expect((float) $r->rows[0]->unitCostLocal)->toBe(1500.0);
});

it('separa la existencia por almacén', function () {
    $f = inventoryFixture();
    $otro = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM2', 'status' => 'active']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
        new StockLineInput($f['item']->id, $otro->id, quantity: 4, unitCostLocal: 1000),
    ]);

    $r = valuation($f, now()->format('Y-m-d'));

    expect($r->rows)->toHaveCount(2)
        ->and((float) $r->totalValueLocal)->toBe(14000.0);
});

it('filtra por almacén', function () {
    $f = inventoryFixture();
    $otro = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM2', 'status' => 'active']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
        new StockLineInput($f['item']->id, $otro->id, quantity: 4, unitCostLocal: 1000),
    ]);

    $r = valuation($f, now()->format('Y-m-d'), warehouseId: $otro->id);

    expect($r->rows)->toHaveCount(1)
        ->and((float) $r->totalValueLocal)->toBe(4000.0);
});

it('oculta las existencias en cero por defecto y las muestra si se piden', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10),
    ]);

    $hoy = now()->format('Y-m-d');

    expect(valuation($f, $hoy)->rows)->toHaveCount(0)
        ->and(valuation($f, $hoy, hideZero: false)->rows)->toHaveCount(1);
});

it('un traslado no cambia el valor total, solo lo reparte', function () {
    $f = inventoryFixture();
    $destino = Warehouse::factory()->create(['company_id' => $f['company']->id, 'code' => 'ALM2', 'status' => 'active']);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    $antes = valuation($f, now()->format('Y-m-d'))->totalValueLocal;

    app(App\Domains\Inventory\Services\PostStockTransferService::class)->post(
        $f['company'], $f['documentType'], now(), now(),
        [new App\Domains\Inventory\DataTransferObjects\StockTransferLineInput(
            $f['item']->id, $f['warehouse']->id, $destino->id, quantity: 4,
        )],
    );

    $despues = valuation($f, now()->format('Y-m-d'));

    expect($despues->totalValueLocal)->toBe($antes)
        ->and($despues->rows)->toHaveCount(2);
});

it('aísla por compañía: no ve el inventario de otra', function () {
    $f = inventoryFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    $otra = inventoryFixture();

    expect(valuation($otra, now()->format('Y-m-d'))->rows)->toHaveCount(0);
});
