<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Models\BillingPaymentAccount;
use App\Domains\Billing\Models\BillingTaxAccount;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Inventory\Exceptions\InsufficientStockException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\StockJournal;

it('emite una factura a crédito: ingresos, IVA y cuenta por cobrar', function () {
    $f = salesFixture();

    // 10 u × ₡2.500 = ₡25.000 + 13% = ₡28.250
    $document = postSale($f);

    $entry = JournalEntry::find($document->journal_entry_id);

    $cxc = $entry->details->firstWhere('account_id', $f['accounts']['receivable']->id);
    $ingresos = $entry->details->firstWhere('account_id', $f['accounts']['revenue']->id);
    $iva = $entry->details->firstWhere('account_id', $f['accounts']['iva']->id);

    expect((float) $cxc->debit_local)->toBe(28250.0)
        ->and((float) $ingresos->credit_local)->toBe(25000.0)
        ->and((float) $iva->credit_local)->toBe(3250.0)
        ->and((float) $document->total_document)->toBe(28250.0);
});

it('LA PRUEBA CENTRAL: la misma venta rebaja stock y lleva su costo a resultados', function () {
    $f = salesFixture();

    $document = postSale($f);

    // 10 u al costo promedio de ₡1.000 = ₡10.000 de costo de ventas.
    $costEntry = JournalEntry::find(
        InventoryDocument::find($document->inventory_document_id)->journal_entry_id
    );

    $cogs = $costEntry->details->firstWhere('account_id', $f['accounts']['cogs']->id);
    $inventario = $costEntry->details->firstWhere('account_id', $f['accounts']['inventory']->id);

    expect((float) $cogs->debit_local)->toBe(10000.0)
        ->and((float) $inventario->credit_local)->toBe(10000.0)
        ->and((float) $f['item']->fresh()->onHand())->toBe(90.0)
        ->and(StockJournal::where('journal_entry_id', $costEntry->id)->sole()->direction)->toBe('out');
});

it('la venta a crédito abre partida pendiente en CxC con su vencimiento', function () {
    $f = salesFixture();

    $document = postSale($f, ['creditTermDays' => 30]);

    $entry = JournalEntry::find($document->journal_entry_id);
    $cxc = $entry->details->firstWhere('account_id', $f['accounts']['receivable']->id);
    $partida = BpOpenItem::where('origin_journal_detail_id', $cxc->id)->sole();

    expect((float) $partida->balance)->toBe(28250.0)
        ->and($partida->status)->toBe('open')
        ->and($document->due_date->format('Y-m-d'))->toBe(now()->addDays(30)->format('Y-m-d'));
});

it('la venta de contado debita las cuentas de cada medio de pago', function () {
    $f = salesFixture();

    $efectivo = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);
    $sinpe = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);

    BillingPaymentAccount::create(['company_id' => $f['company']->id, 'method_code' => '01', 'account_id' => $efectivo->id]);
    BillingPaymentAccount::create(['company_id' => $f['company']->id, 'method_code' => '06', 'account_id' => $sinpe->id]);

    $document = postSale($f, [
        'condition' => '01',
        'creditTermDays' => null,
        'payments' => [
            ['method_code' => '01', 'amount' => 8250],
            ['method_code' => '06', 'amount' => 20000],
        ],
    ]);

    $entry = JournalEntry::find($document->journal_entry_id);

    expect((float) $entry->details->firstWhere('account_id', $efectivo->id)->debit_local)->toBe(8250.0)
        ->and((float) $entry->details->firstWhere('account_id', $sinpe->id)->debit_local)->toBe(20000.0)
        // Sin partida pendiente: la venta de contado ya está cobrada.
        ->and(BpOpenItem::count())->toBe(0);
});

it('rechaza medios de pago que no suman el total del comprobante', function () {
    $f = salesFixture();

    postSale($f, [
        'condition' => '01',
        'creditTermDays' => null,
        'payments' => [['method_code' => '01', 'amount' => 100]],
    ]);
})->throws(InvalidSalesDocumentException::class);

it('genera consecutivo de 20 dígitos y clave de 50 con la estructura de la norma', function () {
    $f = salesFixture();

    $document = postSale($f);

    expect(strlen($document->consecutive))->toBe(20)
        ->and(strlen($document->clave))->toBe(50)
        ->and(ctype_digit($document->clave))->toBeTrue()
        // sucursal(3) + terminal(5) + tipo(2) + número(10)
        ->and(substr($document->consecutive, 0, 3))->toBe('001')
        ->and(substr($document->consecutive, 3, 5))->toBe('00001')
        ->and(substr($document->consecutive, 8, 2))->toBe('01')
        // país + fecha + cédula(12) + consecutivo(20) + situación + seguridad(8)
        ->and(substr($document->clave, 0, 3))->toBe('506')
        ->and(substr($document->clave, 9, 12))->toBe('003101123456')
        ->and(substr($document->clave, 21, 20))->toBe($document->consecutive)
        ->and(substr($document->clave, 41, 1))->toBe('1');
});

it('clasifica el resumen entre servicios y mercancías, y entre gravado y exento', function () {
    $f = salesFixture();

    $document = postSale($f, ['lines' => [
        saleLine($f, 10, 1000),
        saleLine($f, 1, 5000, [
            'isService' => true, 'itemId' => null, 'warehouseId' => null,
            'taxes' => [new SalesTaxInput(ivaRateCode: '10')],
        ]),
    ]]);

    expect((float) $document->total_taxed_goods)->toBe(10000.0)
        ->and((float) $document->total_exempt_services)->toBe(5000.0)
        ->and((float) $document->total_tax)->toBe(1300.0)
        ->and((float) $document->total_document)->toBe(16300.0);
});

it('una línea exonerada se reporta como exonerada y no cobra el impuesto', function () {
    $f = salesFixture();

    $document = postSale($f, ['lines' => [
        saleLine($f, 10, 1000, ['taxes' => [new SalesTaxInput(
            ivaRateCode: '08',
            exonerationDocumentType: '08',
            exonerationDocumentNumber: 'ZF-2026-001',
            exonerationInstitution: '01',
            exoneratedPercentage: 100,
        )]]),
    ]]);

    expect((float) $document->total_exonerated_goods)->toBe(10000.0)
        ->and((float) $document->total_taxed_goods)->toBe(0.0)
        ->and((float) $document->total_tax)->toBe(0.0)
        ->and((float) $document->total_document)->toBe(10000.0);
});

it('aplica el descuento antes de calcular el impuesto', function () {
    $f = salesFixture();

    $document = postSale($f, ['lines' => [
        saleLine($f, 10, 1000, ['discountCode' => '04', 'discountAmount' => 2000]),
    ]]);

    // (10.000 − 2.000) × 13% = 1.040
    expect((float) $document->total_discounts)->toBe(2000.0)
        ->and((float) $document->total_net_sale)->toBe(8000.0)
        ->and((float) $document->total_tax)->toBe(1040.0);
});

it('una factura de solo servicios no toca el inventario', function () {
    $f = salesFixture();

    $document = postSale($f, ['lines' => [
        saleLine($f, 1, 5000, ['isService' => true, 'itemId' => null, 'warehouseId' => null]),
    ]]);

    expect($document->inventory_document_id)->toBeNull()
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('exige plazo en una venta a crédito', function () {
    $f = salesFixture();

    postSale($f, ['creditTermDays' => null]);
})->throws(InvalidSalesDocumentException::class);

it('exige documento de referencia en una nota de crédito', function () {
    $f = salesFixture();

    postSale($f, ['fiscalType' => '03']);
})->throws(InvalidSalesDocumentException::class);

it('solo el tiquete electrónico puede emitirse sin receptor', function () {
    $f = salesFixture();

    postSale($f, ['partnerId' => null]);
})->throws(InvalidSalesDocumentException::class);

it('rechaza vender a un socio que no está registrado como cliente', function () {
    $f = salesFixture();

    $proveedor = BusinessPartner::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'P-001', 'type' => 'supplier',
        'gl_account_id' => $f['accounts']['receivable']->id,
    ]);

    postSale($f, ['partnerId' => $proveedor->id]);
})->throws(InvalidSalesDocumentException::class);

it('rechaza vender más de lo que hay en existencia', function () {
    $f = salesFixture();

    postSale($f, ['lines' => [saleLine($f, 101, 1000)]]);
})->throws(InsufficientStockException::class);

it('ATOMICIDAD: si falla el stock no queda comprobante, ni asiento, ni consecutivo consumido', function () {
    $f = salesFixture();

    $consecutivoAntes = $f['salesType']->fresh()->next_consecutive;
    $asientosAntes = JournalEntry::count();

    try {
        postSale($f, ['lines' => [saleLine($f, 101, 1000)]]);
        $this->fail('Debió lanzar InsufficientStockException.');
    } catch (InsufficientStockException) {
        // esperado
    }

    expect(SalesDocument::count())->toBe(0)
        ->and(JournalEntry::count())->toBe($asientosAntes)
        ->and($f['salesType']->fresh()->next_consecutive)->toBe($consecutivoAntes)
        ->and((float) $f['item']->fresh()->onHand())->toBe(100.0);
});

it('rechaza emitir sin cuenta de IVA configurada para la tarifa usada', function () {
    $f = salesFixture();
    BillingTaxAccount::where('company_id', $f['company']->id)->delete();

    postSale($f);
})->throws(InvalidSalesDocumentException::class);

it('el asiento de la venta cuadra en las tres monedas', function () {
    $f = salesFixture();

    $document = postSale($f);
    $details = JournalDetail::where('journal_entry_id', $document->journal_entry_id)->get();

    foreach (['local', 'foreign', 'system'] as $bucket) {
        expect($details->sum(fn ($d) => (float) $d->{"debit_{$bucket}"}))
            ->toBe($details->sum(fn ($d) => (float) $d->{"credit_{$bucket}"}));
    }
});
