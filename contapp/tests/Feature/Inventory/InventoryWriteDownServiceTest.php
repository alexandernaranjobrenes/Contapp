<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidWriteDownException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\InventoryWriteDown;
use App\Domains\Inventory\Services\InventoryValuationService;
use App\Domains\Inventory\Services\PostInventoryWriteDownService;

/**
 * inventoryFixture() solo trae las tres cuentas del motor de stock; el
 * deterioro necesita además las dos de NIC 2.
 */
function writeDownFixture(): array
{
    $f = inventoryFixture();

    foreach ([
        'write_down_allowance' => 'asset',   // contra-activo
        'write_down_expense' => 'expense',
    ] as $category => $type) {
        $account = ChartOfAccount::factory()->create([
            'company_id' => $f['company']->id,
            'account_type' => $type,
        ]);

        GlDetermination::factory()->create([
            'company_id' => $f['company']->id,
            'scope_level' => 'company',
            'scope_id' => null,
            'category' => $category,
            'account_id' => $account->id,
        ]);

        $f['accounts'][$category] = $account;
    }

    // 10 unidades a ₡1.000 = ₡10.000 de costo.
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10, unitCostLocal: 1000),
    ]);

    return $f;
}

function writeDown(array $f, $nrvUnit, ?string $asOf = null)
{
    return app(PostInventoryWriteDownService::class)->post(
        $f['company'],
        $asOf ?? now()->format('Y-m-d'),
        [['item_id' => $f['item']->id, 'nrv_unit' => $nrvUnit]],
    );
}

function accountBalance(array $f, string $category): string
{
    $rows = JournalDetail::where('account_id', $f['accounts'][$category]->id)->get();

    return number_format(
        (float) $rows->sum('debit_local') - (float) $rows->sum('credit_local'),
        2, '.', ''
    );
}

// ── El cálculo ───────────────────────────────────────────────────────────

it('reconoce deterioro cuando el VNR está por debajo del costo', function () {
    $f = writeDownFixture();

    // Costo 1.000/u, VNR 600/u → estimación de 4.000.
    $wd = writeDown($f, 600);

    expect((float) $wd->lines[0]->target_allowance_local)->toBe(4000.0)
        ->and((float) $wd->lines[0]->movement_local)->toBe(4000.0)
        ->and((float) accountBalance($f, 'write_down_expense'))->toBe(4000.0)
        ->and((float) accountBalance($f, 'write_down_allowance'))->toBe(-4000.0);
});

it('NO reconoce nada cuando el VNR está por encima del costo', function () {
    $f = writeDownFixture();

    // NIC 2 no permite revaluar inventario por encima del costo.
    expect(fn () => writeDown($f, 1500))
        ->toThrow(InvalidWriteDownException::class, 'no hay nada que contabilizar');
});

it('re-avaluar con el mismo VNR no contabiliza nada', function () {
    $f = writeDownFixture();

    writeDown($f, 600);

    expect(fn () => writeDown($f, 600))
        ->toThrow(InvalidWriteDownException::class, 'no hay nada que contabilizar');
});

it('un segundo avalúo peor contabiliza solo el delta', function () {
    $f = writeDownFixture();

    writeDown($f, 600);   // estimación 4.000
    $wd = writeDown($f, 400);  // objetivo 6.000 → delta 2.000

    expect((float) $wd->lines[0]->previous_allowance_local)->toBe(4000.0)
        ->and((float) $wd->lines[0]->movement_local)->toBe(2000.0)
        ->and((float) accountBalance($f, 'write_down_allowance'))->toBe(-6000.0);
});

// ── La reversión del §33 ─────────────────────────────────────────────────

it('LA PRUEBA CENTRAL: reversa cuando el VNR se recupera (NIC 2 §33)', function () {
    $f = writeDownFixture();

    writeDown($f, 600);        // estimación 4.000
    $wd = writeDown($f, 900);  // objetivo 1.000 → reversa 3.000

    expect((float) $wd->lines[0]->movement_local)->toBe(-3000.0)
        ->and((float) accountBalance($f, 'write_down_allowance'))->toBe(-1000.0)
        // La reversión es MENOR gasto, no un ingreso: se acredita la misma
        // cuenta de gasto que se debitó.
        ->and((float) accountBalance($f, 'write_down_expense'))->toBe(1000.0);
});

it('la reversión nunca excede lo reconocido: la estimación no baja de cero', function () {
    $f = writeDownFixture();

    writeDown($f, 600);   // estimación 4.000
    writeDown($f, 1000);  // VNR = costo → objetivo 0, reversa los 4.000

    expect((float) accountBalance($f, 'write_down_allowance'))->toBe(0.0)
        ->and((float) accountBalance($f, 'write_down_expense'))->toBe(0.0);

    // Con el VNR por encima del costo el objetivo sigue siendo 0: no hay
    // nada más que reversar, aunque el mercado haya subido mucho.
    expect(fn () => writeDown($f, 5000))
        ->toThrow(InvalidWriteDownException::class);
});

// ── Lo que NO debe tocar ─────────────────────────────────────────────────

it('LA OTRA PRUEBA CENTRAL: no toca el kardex ni el costo promedio', function () {
    $f = writeDownFixture();

    $kardexAntes = App\Domains\Inventory\Models\StockJournal::count();

    writeDown($f, 600);

    expect(App\Domains\Inventory\Models\StockJournal::count())->toBe($kardexAntes)
        ->and((float) $f['item']->fresh()->avg_cost_local)->toBe(1000.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(10.0);
});

it('el reporte de existencias valorizadas sigue mostrando el COSTO, no el neto', function () {
    $f = writeDownFixture();

    writeDown($f, 600);

    $valuation = app(InventoryValuationService::class)
        ->build($f['company'], now()->format('Y-m-d'));

    // El deterioro vive en su propia cuenta; el inventario sigue valiendo su
    // costo, que es lo que amarra con la cuenta de inventario.
    expect((float) $valuation->totalValueLocal)->toBe(10000.0);
});

// ── Validaciones ─────────────────────────────────────────────────────────

it('rechaza avaluar un artículo sin existencia', function () {
    $f = writeDownFixture();

    postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 10),
    ]);

    expect(fn () => writeDown($f, 600))
        ->toThrow(InvalidWriteDownException::class, 'no tiene existencia');
});

it('rechaza un VNR negativo', function () {
    $f = writeDownFixture();

    expect(fn () => writeDown($f, -5))
        ->toThrow(InvalidWriteDownException::class, 'no puede ser negativo');
});

it('rechaza el mismo artículo dos veces en el mismo documento', function () {
    $f = writeDownFixture();

    expect(fn () => app(PostInventoryWriteDownService::class)->post(
        $f['company'], now()->format('Y-m-d'),
        [
            ['item_id' => $f['item']->id, 'nrv_unit' => 600],
            ['item_id' => $f['item']->id, 'nrv_unit' => 500],
        ],
    ))->toThrow(InvalidWriteDownException::class, 'dos veces');
});

it('rechaza un avalúo vacío', function () {
    $f = writeDownFixture();

    expect(fn () => app(PostInventoryWriteDownService::class)
        ->post($f['company'], now()->format('Y-m-d'), []))
        ->toThrow(InvalidWriteDownException::class, 'al menos un artículo');
});

// ── Integración ──────────────────────────────────────────────────────────

it('crea de oficio el tipo de documento DET la primera vez', function () {
    $f = writeDownFixture();

    writeDown($f, 600);

    $tipo = App\Domains\Core\Models\DocumentType::where('code', 'DET')->sole();

    expect($tipo->origin_module)->toBe('inventario')
        ->and((bool) $tipo->generates_journal)->toBeTrue()
        ->and(InventoryWriteDown::sole()->document_type_id)->toBe($tipo->id);
});

it('el asiento cuadra y queda enlazado al avalúo', function () {
    $f = writeDownFixture();

    $wd = writeDown($f, 600);
    $entry = $wd->journalEntry;

    $detalles = JournalDetail::where('journal_entry_id', $entry->id)->get();

    expect($entry)->not->toBeNull()
        ->and($detalles)->toHaveCount(2)
        ->and((float) $detalles->sum('debit_local'))->toBe((float) $detalles->sum('credit_local'));
});

it('ATOMICIDAD: si falta la determinación de la estimación no queda nada', function () {
    $f = writeDownFixture();

    GlDetermination::where('category', 'write_down_allowance')->delete();

    try {
        writeDown($f, 600);
    } catch (\Throwable) {
        // Se espera que reviente.
    }

    expect(InventoryWriteDown::count())->toBe(0)
        ->and(App\Domains\Inventory\Models\InventoryWriteDownLine::count())->toBe(0)
        ->and((float) accountBalance($f, 'write_down_expense'))->toBe(0.0);
});

it('aísla por compañía: la estimación de otra no se mezcla', function () {
    $f = writeDownFixture();
    writeDown($f, 600);

    $otra = writeDownFixture();

    $allowances = app(PostInventoryWriteDownService::class)
        ->allowancesByItem($otra['company'], now()->format('Y-m-d'));

    expect($allowances)->toBe([]);
});
