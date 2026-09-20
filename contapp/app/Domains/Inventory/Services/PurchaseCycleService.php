<?php

namespace App\Domains\Inventory\Services;

use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\Inventory\Models\ImportCostDocument;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\StockJournal;

/**
 * Arma el mapa del ciclo de compra: entrada → costos de importación →
 * factura → notas de crédito, o bien entrada → anulación.
 *
 * El mapa se puede pedir desde cualquier documento del ciclo —la entrada, una
 * nota, la anulación— y siempre devuelve el ciclo completo visto desde su
 * raíz, con el documento consultado marcado. Es de solo lectura: no decide
 * nada, solo explica qué pasó y qué falta.
 */
class PurchaseCycleService
{
    /**
     * @return array{root_id: int, nodes: array<int, array<string, mixed>>}|null
     */
    public function map(InventoryDocument $document): ?array
    {
        $receipt = $this->rootReceipt($document);

        if (! $receipt) {
            return null;
        }

        $receipt->load([
            'lines', 'documentType:id,code', 'journalEntry:id,document_number',
            'businessPartner:id,code,name', 'invoiceJournalEntry:id,document_number,posting_date',
            'reversal.lines', 'reversal.journalEntry:id,document_number',
            'landedCostDocuments.journalEntry:id,document_number',
            'landedCostDocuments.businessPartner:id,code,name',
            'landedCostDocuments.importCostAllocations.importCost.businessPartner:id,code,name',
        ]);

        $nodes = [$this->receiptNode($receipt, $document)];

        foreach ($receipt->landedCostDocuments as $landedCost) {
            $nodes[] = [
                'kind' => 'landed_cost',
                'label' => 'Costo de importación',
                'date' => $landedCost->posting_date->format('Y-m-d'),
                'detail' => $this->landedCostDetail($landedCost),
                'amount' => (float) $landedCost->capitalized_amount,
                'journal_entry_id' => $landedCost->journal_entry_id,
                'journal_document_number' => $landedCost->journalEntry?->document_number,
                'state' => 'done',
                'current' => false,
                'route' => null,
                'route_params' => null,
            ];
        }

        // Una entrada anulada no sigue el ciclo comercial: su rama termina
        // ahí, y mostrar "pendiente de facturar" al lado sería mentira.
        if ($receipt->reversal) {
            $nodes[] = $this->reversalNode($receipt->reversal, $document);

            return ['root_id' => $receipt->id, 'nodes' => $nodes];
        }

        $nodes[] = $this->invoiceNode($receipt);

        foreach ($this->creditNotes($receipt, $document) as $note) {
            $nodes[] = $note;
        }

        return ['root_id' => $receipt->id, 'nodes' => $nodes];
    }

    private function receiptDetail(InventoryDocument $receipt): string
    {
        $partner = $receipt->businessPartner
            ? $receipt->businessPartner->code.' — '.$receipt->businessPartner->name
            : ($receipt->documentType?->code ?? '');

        if (! $receipt->is_import) {
            return $partner;
        }

        $extras = array_filter([
            $receipt->customs_declaration ? 'DUA '.$receipt->customs_declaration : null,
            $receipt->customsOfficeLabel(),
            $receipt->origin_country,
        ]);

        return $extras ? $partner.' · '.implode(' · ', $extras) : $partner;
    }

    /**
     * Qué dice el nodo de un costeo. Con rubros acumulados lo importante no
     * es el monto —ya está en la columna— sino QUÉ se cargó y de quién:
     * "Flete (Naviera) + Agencia aduanal (AG-001)" explica la importación
     * mucho mejor que "Costo capitalizado".
     */
    private function landedCostDetail($landedCost): string
    {
        $rubros = $landedCost->importCostAllocations
            ->map(fn ($allocation) => ImportCostDocument::CONCEPTS[
                $allocation->importCost?->concept
            ] ?? 'Rubro')
            ->unique()
            ->implode(' + ');

        if ($rubros !== '') {
            return $rubros;
        }

        return $landedCost->description
            ?: ($landedCost->businessPartner?->name ?? 'Costo capitalizado al artículo');
    }

    /**
     * La entrada por compra de la que cuelga todo lo demás. Una nota de
     * crédito la encuentra por source_document_id y una anulación por
     * reversal_of_id; el resto de las operaciones no tienen ciclo de compra.
     */
    private function rootReceipt(InventoryDocument $document): ?InventoryDocument
    {
        return match ($document->operation) {
            'purchase_receipt' => $document,
            'purchase_return' => $document->sourceDocument,
            'purchase_receipt_void' => $document->reversalOf,
            default => null,
        };
    }

    private function receiptNode(InventoryDocument $receipt, InventoryDocument $current): array
    {
        return [
            'kind' => 'receipt',
            'label' => $receipt->is_import ? 'Importación' : 'Entrada por compra',
            'date' => $receipt->posting_date->format('Y-m-d'),
            // En una importación el DUA identifica el trámite mejor que el
            // proveedor: es el número por el que se la busca y se la reclama.
            'detail' => $this->receiptDetail($receipt),
            'amount' => $this->valueOf($receipt),
            'journal_entry_id' => $receipt->journal_entry_id,
            'journal_document_number' => $receipt->journalEntry?->document_number,
            'state' => $receipt->status === 'voided' ? 'voided' : 'done',
            'current' => $receipt->id === $current->id,
            'route' => 'inventory-movements.show',
            'route_params' => $receipt->id,
        ];
    }

    private function reversalNode(InventoryDocument $reversal, InventoryDocument $current): array
    {
        return [
            'kind' => 'void',
            'label' => 'Anulación de la entrada',
            'date' => $reversal->posting_date->format('Y-m-d'),
            'detail' => 'La mercancía salió al costo con que entró y la cuenta puente quedó en cero',
            'amount' => $this->valueOf($reversal),
            'journal_entry_id' => $reversal->journal_entry_id,
            'journal_document_number' => $reversal->journalEntry?->document_number,
            'state' => 'voided',
            'current' => $reversal->id === $current->id,
            'route' => 'inventory-movements.show',
            'route_params' => $reversal->id,
        ];
    }

    /**
     * La factura no es un documento de inventario: vive solo como asiento.
     * Mientras no exista, el nodo queda en 'pending' y es lo que el mapa
     * señala como el siguiente paso del ciclo.
     */
    private function invoiceNode(InventoryDocument $receipt): array
    {
        if (! $receipt->invoice_journal_entry_id) {
            return [
                'kind' => 'invoice',
                'label' => 'Factura del proveedor',
                'date' => null,
                'detail' => 'Pendiente: la deuda sigue en la cuenta puente GR/IR',
                'amount' => null,
                'journal_entry_id' => null,
                'journal_document_number' => null,
                'state' => 'pending',
                'current' => false,
                'route' => 'supplier-invoices.index',
                'route_params' => ['receipt' => $receipt->id],
            ];
        }

        $openItem = BpOpenItem::whereHas(
            'originJournalDetail',
            fn ($q) => $q->where('journal_entry_id', $receipt->invoice_journal_entry_id)
        )->first();

        return [
            'kind' => 'invoice',
            'label' => 'Factura del proveedor',
            'date' => $receipt->invoiceJournalEntry?->posting_date?->format('Y-m-d'),
            'detail' => $openItem
                ? 'Saldo pendiente de pago: '.number_format((float) $openItem->balance, 2)
                : 'Cuenta puente liquidada',
            'amount' => $openItem ? (float) $openItem->original_amount : null,
            'journal_entry_id' => $receipt->invoice_journal_entry_id,
            'journal_document_number' => $receipt->invoiceJournalEntry?->document_number,
            'state' => 'done',
            'current' => false,
            'route' => null,
            'route_params' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function creditNotes(InventoryDocument $receipt, InventoryDocument $current): array
    {
        $notes = $receipt->returns()
            ->with(['lines', 'journalEntry:id,document_number', 'invoiceJournalEntry:id,document_number'])
            ->orderBy('id')
            ->get();

        return $notes->map(fn (InventoryDocument $note) => [
            'kind' => 'credit_note',
            'label' => 'Nota de crédito',
            'date' => $note->posting_date->format('Y-m-d'),
            'detail' => 'Devueltas '.rtrim(rtrim(number_format(
                $note->lines->sum(fn ($l) => (float) $l->quantity), 6, '.', ''
            ), '0'), '.').' u; la deuda bajó en esa proporción',
            'amount' => $this->valueOf($note),
            'journal_entry_id' => $note->invoice_journal_entry_id,
            'journal_document_number' => $note->invoiceJournalEntry?->document_number,
            'state' => 'done',
            'current' => $note->id === $current->id,
            'route' => 'inventory-movements.show',
            'route_params' => $note->id,
        ])->all();
    }

    /**
     * El valor del documento se lee del kardex, no de las líneas: es el monto
     * que realmente se contabilizó, ya redondeado con la misma fórmula que
     * usó el asiento.
     */
    private function valueOf(InventoryDocument $document): float
    {
        return (float) StockJournal::whereIn(
            'inventory_document_line_id', $document->lines->pluck('id')
        )->sum('total_cost_local');
    }
}
