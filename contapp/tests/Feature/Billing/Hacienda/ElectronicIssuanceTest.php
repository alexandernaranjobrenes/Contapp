<?php

use App\Domains\Billing\Exceptions\ElectronicInvoicingException;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\FiscalSequence;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Services\Hacienda\ElectronicDocumentReissuer;
use App\Domains\Billing\Services\Hacienda\FiscalSequenceAllocator;
use App\Domains\Billing\Support\HaciendaClock;
use App\Jobs\SubmitElectronicDocumentJob;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
| Lo que decide si un comprobante se emite electrónicamente, y la regla de
| oro: si algo falla, no queda nada — ni la venta, ni el asiento, ni la
| salida de inventario, ni el consecutivo.
*/

beforeEach(function () {
    Queue::fake();
});

it('con la emisión apagada, facturar sigue como antes: sin firma, sin envío, sin fecha de emisión', function () {
    $f = salesFixture();

    $document = postSale($f);

    expect(ElectronicDocument::count())->toBe(0)
        ->and($document->issued_at)->toBeNull();
    Queue::assertNotPushed(SubmitElectronicDocumentJob::class);
});

it('encendida, la venta queda firmada, con su fecha de emisión y en camino a Hacienda', function () {
    $f = enableHacienda(salesFixture());

    $document = electronicSale($f);
    $electronic = electronicOf($document);

    expect($electronic->status)->toBe(ElectronicDocument::SIGNED)
        ->and($electronic->environment)->toBe('stag')
        ->and($electronic->clave)->toBe($document->clave)
        ->and($document->issued_at)->not->toBeNull()
        ->and($document->journal_entry_id)->not->toBeNull();
    Queue::assertPushed(SubmitElectronicDocumentJob::class);
});

it('incompleta, no emite y dice qué falta: la venta no queda', function () {
    $f = enableHacienda(salesFixture());
    $f['settings']->update(['emails' => []]);

    expect(fn () => electronicSale($f))->toThrow(ElectronicInvoicingException::class, 'Al menos un correo electrónico del emisor');

    expect(SalesDocument::count())->toBe(0)
        ->and(FiscalSequence::count())->toBe(0);
});

it('una factura electrónica exige un receptor identificado', function () {
    $f = enableHacienda(salesFixture());
    $f['customer']->update(['identification_type' => null]);

    expect(fn () => electronicSale($f))->toThrow(ElectronicInvoicingException::class, 'tipo de identificación');
    expect(SalesDocument::count())->toBe(0);
});

it('rechaza una cédula que no tiene los dígitos de su tipo', function () {
    $f = enableHacienda(salesFixture());
    $f['customer']->update(['identification_type' => '01', 'tax_id' => '3101654321']);

    expect(fn () => electronicSale($f))->toThrow(ElectronicInvoicingException::class, 'cédula física');
});

it('un comprobante electrónico se emite con la fecha de hoy (A15)', function () {
    $f = enableHacienda(salesFixture());

    expect(fn () => electronicSale($f, ['documentDate' => new DateTimeImmutable('-3 days')]))
        ->toThrow(ElectronicInvoicingException::class, 'fecha de hoy');
});

it('la actividad económica tiene que ir con el formato de la v4.4 (L3)', function () {
    $f = enableHacienda(salesFixture());
    $f['activity']->update(['code' => '620100']);

    expect(fn () => electronicSale($f))->toThrow(ElectronicInvoicingException::class, '6201.0');
});

it('cada línea lleva su impuesto (L7): una línea sin impuesto no se emite', function () {
    $f = enableHacienda(salesFixture());

    expect(fn () => electronicSale($f, ['lines' => [saleLine($f, 1, 1000, ['taxes' => []])]]))
        ->toThrow(ElectronicInvoicingException::class, 'no tiene impuesto');
});

it('una nota de crédito sobre una factura que Hacienda todavía no aceptó no se emite (A16)', function () {
    $f = enableHacienda(salesFixture());
    $invoice = electronicSale($f);

    expect(fn () => electronicSale($f, [
        'fiscalType' => '03',
        'originalId' => $invoice->id,
        'references' => [['document_type' => '01', 'number' => $invoice->clave, 'reason_code' => '01', 'reason' => 'Anula la factura']],
    ]))->toThrow(ElectronicInvoicingException::class, 'solo se puede corregir uno aceptado');
});

it('la factura de compra y el recibo de pago no se emiten desde Facturación', function () {
    $f = enableHacienda(salesFixture());

    expect(fn () => electronicSale($f, ['fiscalType' => '08', 'references' => [['document_type' => '99', 'number' => 'X-1', 'issued_at' => now()->format('Y-m-d'), 'reason_code' => '99', 'reason' => 'Compra']]]))
        ->toThrow(ElectronicInvoicingException::class, 'no se emite electrónicamente');
});

// --- Consecutivos ---

it('la serie fiscal arranca después del último consecutivo que ya se usó', function () {
    $f = salesFixture();

    // Dos comprobantes antes de encender la emisión electrónica.
    postSale($f);
    $last = postSale($f);

    $f = enableHacienda($f);
    $document = electronicSale($f);

    expect((int) substr($document->consecutive, -10))->toBe((int) substr($last->consecutive, -10) + 1);
});

it('el Superusuario puede seguir la numeración que traía, pero nunca hacia atrás', function () {
    $f = enableHacienda(salesFixture());
    $allocator = app(FiscalSequenceAllocator::class);

    $allocator->setNext($f['company'], '001', '00001', '01', 1500);
    $document = electronicSale($f);

    expect(substr($document->consecutive, -10))->toBe('0000001500');

    expect(fn () => $allocator->setNext($f['company'], '001', '00001', '01', 1200))
        ->toThrow(ElectronicInvoicingException::class, 'ya se usó');
});

it('cada sucursal y terminal lleva su propia serie', function () {
    $f = enableHacienda(salesFixture());

    electronicSale($f);
    $second = electronicSale($f);
    $otherTerminal = electronicSale($f, ['terminal' => '00002']);

    expect(substr($second->consecutive, 0, 8))->toBe('00100001')
        ->and(substr($second->consecutive, -10))->toBe('0000000002')
        ->and(substr($otherTerminal->consecutive, 0, 8))->toBe('00100002')
        ->and(substr($otherTerminal->consecutive, -10))->toBe('0000000001');
});

// --- Volver a emitir un rechazado ---

it('un rechazado se vuelve a emitir con consecutivo, clave y fecha nuevos; el anterior queda sustituido (L14, L16)', function () {
    $f = enableHacienda(salesFixture());
    $document = electronicSale($f);
    $first = electronicOf($document);
    $first->update(['status' => ElectronicDocument::REJECTED]);

    $this->travel(5)->minutes();

    $second = app(ElectronicDocumentReissuer::class)->reissue($f['company'], $document);
    $document->refresh();

    expect($second->id)->not->toBe($first->id)
        ->and($second->clave)->not->toBe($first->clave)
        ->and($second->consecutive)->not->toBe($first->consecutive)
        ->and($second->status)->toBe(ElectronicDocument::SIGNED)
        ->and($first->fresh()->status)->toBe(ElectronicDocument::SUPERSEDED)
        ->and($first->fresh()->superseded_by_id)->toBe($second->id)
        // La venta lleva ahora la identidad fiscal nueva, y la clave su fecha.
        ->and($document->clave)->toBe($second->clave)
        ->and(substr($second->clave, 3, 6))->toBe(HaciendaClock::local($second->issued_at)->format('dmy'));

    expectValidAgainstXsd($second);
});

it('no se vuelve a emitir uno aceptado, ni uno que Hacienda todavía procesa', function () {
    $f = enableHacienda(salesFixture());
    $document = electronicSale($f);
    electronicOf($document)->update(['status' => ElectronicDocument::SUBMITTED]);

    expect(fn () => app(ElectronicDocumentReissuer::class)->reissue($f['company'], $document))
        ->toThrow(ElectronicInvoicingException::class, 'Solo se vuelve a emitir');
});
