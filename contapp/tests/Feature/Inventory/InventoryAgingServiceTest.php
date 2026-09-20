<?php

use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\BusinessPartners\Support\DayBucketScheme;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Services\InventoryAgingService;

/**
 * inventoryFixture() solo abre el período y el tipo de cambio del mes en
 * curso, y estos tests contabilizan hasta 300 días atrás para poder medir
 * antigüedad de verdad. Esta variante abre los dos años anteriores completos.
 */
function inventoryAgingFixture(): array
{
    $f = inventoryFixture();

    ExchangeRate::factory()->create([
        'company_id' => $f['company']->id,
        'currency_id' => $f['company']->foreign_currency_id,
        'rate_date' => now()->subYears(3)->format('Y-m-d'),
        'rate' => '500.000000',
    ]);

    foreach ([now()->year - 2, now()->year - 1, now()->year] as $year) {
        // El año en curso ya existe (lo crea inventoryFixture) pero solo con
        // el período del mes actual: hay que completarle los otros once, no
        // saltárselo.
        $fiscalYear = FiscalYear::firstOrCreate(
            ['company_id' => $f['company']->id, 'year' => $year],
            ['status' => 'open'],
        );

        for ($month = 1; $month <= 12; $month++) {
            if (FiscalPeriod::where('fiscal_year_id', $fiscalYear->id)->where('period_number', $month)->exists()) {
                continue;
            }

            $start = Carbon\Carbon::create($year, $month, 1);

            FiscalPeriod::factory()->create([
                'fiscal_year_id' => $fiscalYear->id,
                'period_number' => $month,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $start->copy()->endOfMonth()->format('Y-m-d'),
                'status' => 'open',
            ]);
        }
    }

    return $f;
}

function inventoryAging(array $f, ?string $asOf = null, ?string $buckets = null, ?int $warehouseId = null)
{
    return app(InventoryAgingService::class)
        ->build($f['company'], $asOf ?? now()->format('Y-m-d'), $buckets, $warehouseId);
}

it('mide la antigüedad desde la última salida, no desde el último movimiento', function () {
    $f = inventoryAgingFixture();

    // Entra hace 100 días, sale hace 80, y vuelve a ENTRAR hace 5.
    $this->travelTo(now()->subDays(100));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 100, unitCostLocal: 10),
    ]);

    $this->travelTo(now()->addDays(20));
    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10),
    ]);

    $this->travelTo(now()->addDays(75));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 50, unitCostLocal: 10),
    ]);

    $this->travelBack();

    // Si midiera desde el último movimiento diría 5 días. La entrada reciente
    // no rota nada: lleva 80 sin vender, que es lo que importa.
    expect(inventoryAging($f)->rows[0]->daysIdle)->toBe(80)
        ->and(inventoryAging($f)->rows[0]->neverIssued)->toBeFalse();
});

it('un artículo que nunca ha salido se cuenta desde que entró y va marcado', function () {
    $f = inventoryAgingFixture();

    $this->travelTo(now()->subDays(200));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);
    $this->travelBack();

    $row = inventoryAging($f)->rows[0];

    expect($row->daysIdle)->toBe(200)
        ->and($row->neverIssued)->toBeTrue();
});

it('clasifica en el tramo que corresponde', function () {
    $f = inventoryAgingFixture();

    $this->travelTo(now()->subDays(200));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);
    $this->travelBack();

    // Cortes por defecto: 30, 60, 90, 180, 360 → 200 días cae en 181-360.
    expect(inventoryAging($f)->rows[0]->bucket)->toBe('d_181_360');
});

it('los cortes son configurables', function () {
    $f = inventoryAgingFixture();

    $this->travelTo(now()->subDays(200));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);
    $this->travelBack();

    // Con cortes de 100 en 100, los mismos 200 días caen en 101-200.
    expect(inventoryAging($f, buckets: '100,200,300')->rows[0]->bucket)->toBe('d_101_200');
});

it('un corte mal formado cae al default en vez de reventar', function () {
    $f = inventoryAgingFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);

    expect(inventoryAging($f, buckets: 'basura,,-5')->bucketsInput)
        ->toBe(implode(',', DayBucketScheme::INVENTORY_DEFAULT));
});

it('lo que ya salió completo no aparece: sin existencia no hay obsolescencia', function () {
    $f = inventoryAgingFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10),
    ]);

    expect(inventoryAging($f)->rows)->toHaveCount(0);
});

it('el total por tramos suma el total general', function () {
    $f = inventoryAgingFixture();

    $this->travelTo(now()->subDays(200));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);
    $this->travelBack();

    $r = inventoryAging($f);
    $suma = array_reduce($r->bucketTotals, fn ($carry, $v) => bcadd($carry, $v, 2), '0.00');

    expect($suma)->toBe($r->totalValueLocal)
        ->and((float) $r->totalValueLocal)->toBe(1000.0);
});

it('ordena de lo más parado a lo menos parado', function () {
    $f = inventoryAgingFixture();

    $viejo = $f['item'];
    $nuevo = App\Domains\Inventory\Models\Item::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ART-2', 'uom_id' => $viejo->uom_id,
    ]);

    $this->travelTo(now()->subDays(300));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($viejo->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);

    $this->travelTo(now()->addDays(290));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($nuevo->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);
    $this->travelBack();

    expect(collect(inventoryAging($f)->rows)->pluck('itemCode')->all())->toBe(['ART-1', 'ART-2']);
});

it('filtra por almacén', function () {
    $f = inventoryAgingFixture();
    $otro = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM2', 'status' => 'active',
    ]);

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
        new StockLineInput($f['item']->id, $otro->id, quantity: 4, unitCostLocal: 100),
    ]);

    expect(inventoryAging($f, warehouseId: $otro->id)->rows)->toHaveCount(1)
        ->and((float) inventoryAging($f, warehouseId: $otro->id)->totalValueLocal)->toBe(400.0);
});

it('el corte retroactivo ignora las salidas posteriores', function () {
    $f = inventoryAgingFixture();

    $this->travelTo(now()->subDays(50));
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);
    $this->travelBack();

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10),
    ]);

    // Hoy no queda existencia, pero al corte de hace 10 días sí la había y
    // llevaba 40 días sin rotar.
    $corte = now()->subDays(10)->format('Y-m-d');

    expect(inventoryAging($f)->rows)->toHaveCount(0)
        ->and(inventoryAging($f, asOf: $corte)->rows[0]->daysIdle)->toBe(40);
});

it('aísla por compañía', function () {
    $f = inventoryAgingFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 100),
    ]);

    expect(inventoryAging(inventoryAgingFixture())->rows)->toHaveCount(0);
});
