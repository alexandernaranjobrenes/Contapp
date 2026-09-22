<?php

use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidStockMovementException;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Services\PostStockMovementService;

function serialFixture(): array
{
    $f = purchaseFixture();
    $f['item']->update(['tracks_serials' => true]);

    return $f;
}

/**
 * @param  string[]  $serials
 */
function moveSerials(array $f, string $operation, array $serials, $quantity = null, $unitCost = 100)
{
    return postMovement($f, $operation, [
        new StockLineInput(
            $f['item']->id,
            $f['warehouse']->id,
            quantity: $quantity ?? count($serials),
            unitCostLocal: $operation === 'goods_receipt' ? $unitCost : null,
            serialNumbers: $serials,
        ),
    ]);
}

function serialsInStock(array $f)
{
    return ItemSerial::where('item_id', $f['item']->id)->inStock()->pluck('serial_number')->sort()->values()->all();
}

// ── La regla central ─────────────────────────────────────────────────────

it('LA PRUEBA CENTRAL: una serie por unidad, ni una más ni una menos', function () {
    $f = serialFixture();

    // Tres unidades con dos series: el maestro y la existencia empezarían a
    // separarse desde el primer documento.
    expect(fn () => moveSerials($f, 'goods_receipt', ['S-1', 'S-2'], quantity: 3))
        ->toThrow(InvalidStockMovementException::class, 'exactamente una serie por unidad');

    expect(fn () => moveSerials($f, 'goods_receipt', ['S-1', 'S-2', 'S-3'], quantity: 2))
        ->toThrow(InvalidStockMovementException::class, 'exactamente una serie por unidad');

    expect(ItemSerial::count())->toBe(0);
});

it('EL INVARIANTE: las series en existencia cuadran con on_hand', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1', 'S-2', 'S-3']);
    moveSerials($f, 'goods_issue', ['S-2']);

    $onHand = ItemWarehouse::where('item_id', $f['item']->id)
        ->where('warehouse_id', $f['warehouse']->id)
        ->value('on_hand');

    // Este es el invariante que agrega toda la capa: si alguna vez deja de
    // cumplirse, hay una serie que el inventario cree tener y no está, o al
    // revés.
    expect(count(serialsInStock($f)))->toBe((int) (float) $onHand)
        ->and(serialsInStock($f))->toBe(['S-1', 'S-3']);
});

it('un artículo serializado no admite cantidades fraccionarias', function () {
    $f = serialFixture();

    // Media unidad no tiene número de serie.
    expect(fn () => moveSerials($f, 'goods_receipt', ['S-1'], quantity: 1.5))
        ->toThrow(InvalidStockMovementException::class, 'cantidad tiene que ser entera');
});

it('rechaza series repetidas dentro de la misma línea', function () {
    $f = serialFixture();

    expect(fn () => moveSerials($f, 'goods_receipt', ['S-1', 'S-1']))
        ->toThrow(InvalidStockMovementException::class, 'vienen repetidas');
});

// ── Entrada ──────────────────────────────────────────────────────────────

it('la entrada crea las series con su documento y su fecha', function () {
    $f = serialFixture();

    $document = moveSerials($f, 'goods_receipt', ['S-1', 'S-2']);

    $serial = ItemSerial::where('serial_number', 'S-1')->sole();

    expect($serial->status)->toBe('in_stock')
        ->and($serial->warehouse_id)->toBe($f['warehouse']->id)
        ->and($serial->received_document_id)->toBe($document->id)
        ->and($serial->received_at->format('Y-m-d'))->toBe($document->posting_date->format('Y-m-d'));
});

it('LA PRUEBA DEL DUPLICADO: una serie que ya está en existencia no puede volver a entrar', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1']);

    // O es un número tecleado mal, o es la misma unidad contada dos veces.
    // Las dos terminan en un inventario que dice tener algo que no tiene.
    expect(fn () => moveSerials($f, 'goods_receipt', ['S-1']))
        ->toThrow(InvalidStockMovementException::class, 'ya están en existencia');

    expect(ItemSerial::where('serial_number', 'S-1')->count())->toBe(1);
});

it('una serie que salió SÍ puede volver a entrar, y conserva su historial', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1']);
    moveSerials($f, 'goods_issue', ['S-1']);

    // Una devolución de cliente: es la misma unidad física, no una nueva.
    moveSerials($f, 'goods_receipt', ['S-1']);

    $serial = ItemSerial::where('serial_number', 'S-1')->sole();

    expect(ItemSerial::where('serial_number', 'S-1')->count())->toBe(1)
        ->and($serial->status)->toBe('in_stock')
        // Los datos de salida se limpian: dejarlos haría creer que la sigue
        // teniendo el cliente.
        ->and($serial->issued_document_id)->toBeNull()
        ->and($serial->issued_at)->toBeNull();
});

// ── Salida ───────────────────────────────────────────────────────────────

it('la salida marca la serie como entregada y la enlaza a su documento', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1']);
    $issue = moveSerials($f, 'goods_issue', ['S-1']);

    $serial = ItemSerial::where('serial_number', 'S-1')->sole();

    expect($serial->status)->toBe('issued')
        ->and($serial->issued_document_id)->toBe($issue->id)
        // El almacén de donde salió se conserva: de dónde salió es parte de
        // la trazabilidad.
        ->and($serial->warehouse_id)->toBe($f['warehouse']->id);
});

it('LA PRUEBA DE LA DOBLE SALIDA: una serie no puede salir dos veces', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1', 'S-2']);
    moveSerials($f, 'goods_issue', ['S-1']);

    expect(fn () => moveSerials($f, 'goods_issue', ['S-1']))
        ->toThrow(InvalidStockMovementException::class, 'no puede salir dos veces');
});

it('rechaza sacar una serie que no existe en el maestro', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1']);

    expect(fn () => moveSerials($f, 'goods_issue', ['S-999']))
        ->toThrow(InvalidStockMovementException::class, 'no existen en el maestro');
});

it('rechaza sacar una serie que está en otro almacén', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1']);

    $otro = App\Domains\Inventory\Models\Warehouse::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'ALM-2',
    ]);

    // El otro almacén tiene existencia propia —su serie S-9— así que la
    // validación de cantidad pasa y lo que falla es la serie: S-1 no está
    // ahí. Sin esta entrada saltaría antes la falta de existencia y la
    // prueba no probaría lo que dice.
    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $otro->id, quantity: 1, unitCostLocal: 100, serialNumbers: ['S-9']),
    ]);

    // Sacarla de donde no está sería mover existencia que ese almacén no
    // tiene, aunque el total del artículo cuadre.
    expect(fn () => postMovement($f, 'goods_issue', [
        new StockLineInput($f['item']->id, $otro->id, quantity: 1, serialNumbers: ['S-1']),
    ]))->toThrow(InvalidStockMovementException::class, 'no está en el almacén de la línea');
});

// ── Artículos que no manejan series ──────────────────────────────────────

it('un artículo sin series no admite que se las indiquen', function () {
    $f = purchaseFixture();

    expect(fn () => moveSerials($f, 'goods_receipt', ['S-1']))
        ->toThrow(InvalidStockMovementException::class, 'no maneja números de serie');
});

it('un artículo con series exige que se indiquen', function () {
    $f = serialFixture();

    expect(fn () => postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 2, unitCostLocal: 100),
    ]))->toThrow(InvalidStockMovementException::class, 'exactamente una serie por unidad');
});

it('los artículos sin series siguen moviéndose igual que siempre', function () {
    $f = purchaseFixture();

    postMovement($f, 'goods_receipt', [
        new StockLineInput($f['item']->id, $f['warehouse']->id, quantity: 7.5, unitCostLocal: 100),
    ]);

    // Ni cantidad entera, ni series, ni nada: la capa es invisible para el
    // resto del catálogo.
    expect((float) $f['item']->fresh()->onHand())->toBe(7.5)
        ->and(ItemSerial::count())->toBe(0);
});

// ── La frontera con el costeo ────────────────────────────────────────────

it('LA PRUEBA DE LA FRONTERA: las series no tocan el costo promedio', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1'], unitCost: 100);
    moveSerials($f, 'goods_receipt', ['S-2'], unitCost: 300);

    // El costo sigue siendo promedio ponderado global del artículo: (100 +
    // 300) / 2. Que cada unidad esté identificada NO la convierte en una
    // unidad con costo propio — eso sería identificación específica (NIC 2
    // §23) y es otro motor de costeo, no una columna más.
    expect((float) $f['item']->fresh()->avg_cost_local)->toBe(200.0);

    // Y al sacar la serie S-1, que entró a 100, sale al promedio.
    moveSerials($f, 'goods_issue', ['S-1']);

    $salida = App\Domains\Inventory\Models\StockJournal::where('direction', 'out')->latest('id')->first();

    expect((float) $salida->unit_cost_local)->toBe(200.0);
});

// ── Anulación ────────────────────────────────────────────────────────────

it('anular la entrada borra las series que trajo', function () {
    $f = serialFixture();

    // Solo la entrada por compra se anula; el resto del ciclo se corrige con
    // su documento espejo, y una entrada por compra exige proveedor.
    $receipt = app(PostStockMovementService::class)->post(
        $f['company'], $f['documentType'], 'purchase_receipt', now(), now(),
        [new StockLineInput(
            $f['item']->id, $f['warehouse']->id,
            quantity: 2, unitCostLocal: 100, serialNumbers: ['S-1', 'S-2'],
        )],
        businessPartnerId: $f['supplier']->id,
    );

    app(PostStockMovementService::class)->void($f['company'], $receipt, now());

    // Se borran y no se marcan como entregadas: "entregada" significa que
    // alguien la tiene, y acá el documento se anuló — la unidad nunca
    // ingresó.
    expect(ItemSerial::where('item_id', $f['item']->id)->count())->toBe(0);
});

// ── Consultas ────────────────────────────────────────────────────────────

it('la garantía se evalúa contra una fecha, no contra el reloj', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1']);

    $serial = ItemSerial::where('serial_number', 'S-1')->sole();
    $serial->update(['warranty_until' => '2027-06-30']);

    // Una reclamación se evalúa contra el día en que se presentó.
    expect($serial->isUnderWarrantyOn(new DateTime('2027-06-30')))->toBeTrue()
        ->and($serial->isUnderWarrantyOn(new DateTime('2027-07-01')))->toBeFalse();
});

it('una serie sin fecha de garantía no está en garantía', function () {
    $f = serialFixture();

    moveSerials($f, 'goods_receipt', ['S-1']);

    // A diferencia del vencimiento de un lote —donde "sin fecha" significa
    // que no caduca—, acá la ausencia significa que no se registró
    // cobertura.
    expect(ItemSerial::where('serial_number', 'S-1')->sole()->isUnderWarrantyOn(new DateTime('2026-01-01')))
        ->toBeFalse();
});
