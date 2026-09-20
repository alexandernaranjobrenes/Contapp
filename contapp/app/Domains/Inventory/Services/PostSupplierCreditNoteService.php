<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\DataTransferObjects\PurchaseReturnLineInput;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidPurchaseReturnException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Support\Facades\DB;

/**
 * Nota de crédito de proveedor por devolución de mercancía. Es el espejo
 * exacto del ciclo de compra y se apoya en las mismas piezas:
 *
 *   Compra:     recepción  Debe Inventario / Haber GR/IR
 *               factura    Debe GR/IR      / Haber Cuentas por Pagar
 *
 *   Devolución: salida     Debe GR/IR      / Haber Inventario
 *               nota       Debe CxP        / Haber GR/IR + Haber IVA
 *
 * La cuenta puente vuelve a hacer de bisagra, así que cierra en cero igual que
 * en la compra. El movimiento de stock lo hace PostStockMovementService —el
 * kardex tiene un solo dueño— y la nota se contabiliza acá, todo en una sola
 * transacción: si algo falla no queda ni salida de mercancía ni nota.
 *
 * La mercancía sale al COSTO PROMEDIO vigente, pero el proveedor acredita al
 * precio que él acepte. Esa diferencia es real —devolver algo que hoy vale más
 * de lo que te acreditan es una pérdida— y va a la cuenta de diferencia de
 * precio, el mismo criterio que ya usan los costos de importación.
 */
class PostSupplierCreditNoteService
{
    public function __construct(
        private readonly PostStockMovementService $postStockMovementService,
        private readonly PostJournalService $postJournalService,
        private readonly GlDeterminationResolver $glResolver,
    ) {}

    /**
     * @param  PurchaseReturnLineInput[]  $lines
     */
    public function post(
        Company $company,
        DocumentType $documentType,
        InventoryDocument $receipt,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        array $lines,
        ?int $taxAccountId = null,
        int|float|string $taxAmount = 0,
        ?string $description = null,
        ?int $createdBy = null,
    ): InventoryDocument {
        $this->assertReceiptIsReturnable($company, $receipt);

        if (empty($lines)) {
            throw new InvalidPurchaseReturnException('Una devolución requiere al menos una línea.');
        }

        $tax = number_format((float) $taxAmount, 2, '.', '');

        if ($taxAccountId === null && bccomp($tax, '0.00', 2) !== 0) {
            throw new InvalidPurchaseReturnException('Un monto de impuesto requiere indicar la cuenta de IVA que lo revierte.');
        }

        return DB::transaction(function () use ($company, $documentType, $receipt, $documentDate, $postingDate, $lines, $taxAccountId, $tax, $description, $createdBy) {
            $locked = InventoryDocument::withoutGlobalScope(CompanyScope::class)
                ->with(['lines.warehouse'])
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->first();

            [$stockLines, $creditedNet] = $this->resolveLines($locked, $lines);

            // 1) La mercancía sale del inventario contra la cuenta puente,
            //    exactamente al revés de como entró.
            $returnDocument = $this->postStockMovementService->post(
                company: $company,
                documentType: $documentType,
                operation: 'purchase_return',
                documentDate: $documentDate,
                postingDate: $postingDate,
                lines: $stockLines,
                description: $description ?? "Devolución al proveedor — recepción #{$locked->id}",
                createdBy: $createdBy,
                businessPartnerId: $locked->business_partner_id,
                sourceDocumentId: $locked->id,
            );

            // 2) La nota liquida esa puente contra la deuda con el proveedor.
            $entry = $this->postCreditNote(
                $company, $documentType, $locked, $returnDocument, $creditedNet,
                $taxAccountId, $tax, $documentDate, $postingDate, $description, $createdBy
            );

            $returnDocument->update(['invoice_journal_entry_id' => $entry->id]);

            return $returnDocument->load('lines');
        });
    }

    private function assertReceiptIsReturnable(Company $company, InventoryDocument $receipt): void
    {
        if ($receipt->company_id !== $company->id) {
            throw new \InvalidArgumentException('La recepción no pertenece a la compañía indicada.');
        }

        if ($receipt->operation !== 'purchase_receipt') {
            throw new InvalidPurchaseReturnException('Solo una entrada por compra admite nota de crédito de proveedor.');
        }

        if ($receipt->status !== 'posted') {
            throw new InvalidPurchaseReturnException('La recepción está anulada; no admite devoluciones.');
        }

        // Sin factura no hay deuda que acreditar: lo que corresponde es no
        // facturar la recepción, no emitir una nota contra nada.
        if ($receipt->invoice_journal_entry_id === null) {
            throw new InvalidPurchaseReturnException(
                'La recepción todavía no está facturada; una nota de crédito necesita una factura que acreditar.'
            );
        }
    }

    /**
     * Traduce las líneas de devolución a líneas de movimiento de stock, y de
     * paso acumula cuánto acredita el proveedor.
     *
     * @param  PurchaseReturnLineInput[]  $lines
     * @return array{0: StockLineInput[], 1: string}
     */
    private function resolveLines(InventoryDocument $receipt, array $lines): array
    {
        $alreadyReturned = $this->returnedQuantities($receipt);

        $stockLines = [];
        $creditedNet = '0.00';

        foreach ($lines as $line) {
            $receiptLine = $receipt->lines->firstWhere('id', $line->receiptLineId);

            if (! $receiptLine) {
                throw new InvalidPurchaseReturnException(
                    "La línea id {$line->receiptLineId} no pertenece a la recepción #{$receipt->id}."
                );
            }

            $pending = bcsub(
                (string) $receiptLine->quantity,
                $alreadyReturned[$receiptLine->id] ?? '0.000000',
                6
            );

            if (bccomp($line->quantity, $pending, 6) > 0) {
                throw new InvalidPurchaseReturnException(
                    'No se puede devolver '.$this->trim($line->quantity)." de la línea {$receiptLine->line_number}: ".
                    'quedan '.$this->trim($pending).' sin devolver.'
                );
            }

            $unitPrice = $line->creditedUnitPrice ?? (string) $receiptLine->unit_cost_local;
            $creditedNet = bcadd($creditedNet, $this->money(bcmul($line->quantity, $unitPrice, 12)), 2);

            $stockLines[] = new StockLineInput(
                itemId: $receiptLine->item_id,
                warehouseId: $receiptLine->warehouse_id,
                quantity: $line->quantity,
                description: $line->description,
                warehouseBinId: $receiptLine->warehouse_bin_id,
            );
        }

        return [$stockLines, $creditedNet];
    }

    /**
     * Cuánto se devolvió ya de cada línea, para que varias notas sucesivas no
     * puedan devolver más de lo que entró.
     *
     * El tope se lleva por artículo + almacén y no por id de línea, porque una
     * devolución no guarda contra qué línea concreta se hizo: esa pareja es la
     * que identifica una línea de recepción, y usarla evita duplicar el dato.
     *
     * @return array<int, string>
     */
    private function returnedQuantities(InventoryDocument $receipt): array
    {
        $returns = InventoryDocument::withoutGlobalScope(CompanyScope::class)
            ->with('lines')
            ->where('source_document_id', $receipt->id)
            ->where('operation', 'purchase_return')
            ->where('status', 'posted')
            ->get();

        $byItem = [];

        foreach ($returns as $return) {
            foreach ($return->lines as $line) {
                $key = $line->item_id.':'.$line->warehouse_id;
                $byItem[$key] = bcadd($byItem[$key] ?? '0.000000', (string) $line->quantity, 6);
            }
        }

        $byReceiptLine = [];

        foreach ($receipt->lines as $receiptLine) {
            $key = $receiptLine->item_id.':'.$receiptLine->warehouse_id;
            $byReceiptLine[$receiptLine->id] = $byItem[$key] ?? '0.000000';
        }

        return $byReceiptLine;
    }

    private function postCreditNote(
        Company $company,
        DocumentType $documentType,
        InventoryDocument $receipt,
        InventoryDocument $returnDocument,
        string $creditedNet,
        ?int $taxAccountId,
        string $tax,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        ?string $description,
        ?int $createdBy,
    ): JournalEntry {
        $supplier = $receipt->businessPartner()->withoutGlobalScope(CompanyScope::class)->first();

        if (! $supplier) {
            throw new InvalidPurchaseReturnException('La recepción no tiene un proveedor válido asociado.');
        }

        $returnEntry = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->with('details')
            ->findOrFail($returnDocument->journal_entry_id);

        $lines = [];
        $bridged = '0.00';
        $rate = (string) $returnEntry->details->first()->exchange_rate_lc_fc;

        // El lado DEUDOR del asiento de salida es la cuenta puente (el acreedor
        // es inventario). Espejarlo la deja exactamente en cero — misma técnica
        // que usa la factura sobre la recepción, y por la misma razón: si
        // alguien reconfiguró la matriz entre medio, volver a resolverla
        // acreditaría otra cuenta y la puente nunca cerraría.
        foreach ($returnEntry->details as $detail) {
            if (bccomp((string) $detail->debit_local, '0.00', 2) <= 0) {
                continue;
            }

            $lines[] = new JournalLineInput(
                accountId: $detail->account_id,
                currencyId: $detail->currency_id,
                debit: 0,
                credit: $detail->debit_local,
                description: $detail->description,
                frozenExchangeRate: $detail->exchange_rate_lc_fc,
            );

            $bridged = bcadd($bridged, (string) $detail->debit_local, 2);
        }

        if (empty($lines)) {
            throw new InvalidPurchaseReturnException('El asiento de la devolución no tiene cuenta puente que liquidar.');
        }

        if (bccomp($tax, '0.00', 2) > 0) {
            $lines[] = $this->taxLine($company, $taxAccountId, $tax, $creditedNet, $rate);
        }

        $lines[] = new JournalLineInput(
            accountId: $supplier->gl_account_id,
            currencyId: $company->local_currency_id,
            debit: bcadd($creditedNet, $tax, 2),
            credit: 0,
            description: $description ?? "Nota de crédito — {$supplier->name}",
            businessPartnerId: $supplier->id,
            // Aplica contra la partida abierta de la factura original: sin
            // esto la deuda bajaría en el mayor pero la partida seguiría
            // mostrando el saldo completo en antigüedad de saldos.
            applyToOpenItemId: $this->openItemOf($receipt)?->id,
            frozenExchangeRate: $rate,
        );

        // La mercancía sale al costo promedio de hoy y el proveedor acredita a
        // su precio: la diferencia es un resultado real, no un descuadre.
        $difference = bcsub($bridged, $creditedNet, 2);

        if (bccomp($difference, '0.00', 2) !== 0) {
            $lines[] = $this->priceDifferenceLine($company, $documentType, $receipt, $difference, $rate);
        }

        return $this->postJournalService->post(
            company: $company,
            documentType: $documentType,
            documentDate: $documentDate,
            postingDate: $postingDate,
            lines: $lines,
            description: $description ?? "Nota de crédito de compra — {$supplier->name}",
            createdBy: $createdBy,
        );
    }

    private function taxLine(Company $company, ?int $taxAccountId, string $tax, string $base, string $rate): JournalLineInput
    {
        $taxAccount = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->find($taxAccountId);

        if (! $taxAccount) {
            throw new InvalidPurchaseReturnException("La cuenta de IVA id {$taxAccountId} no existe en la compañía.");
        }

        if (! $taxAccount->tax_rate_id) {
            throw new InvalidPurchaseReturnException(
                "La cuenta {$taxAccount->code} ({$taxAccount->description_es}) no tiene un indicador de impuesto vinculado."
            );
        }

        $taxRate = TaxRate::findOrFail($taxAccount->tax_rate_id);

        return new JournalLineInput(
            accountId: $taxAccount->id,
            currencyId: $company->local_currency_id,
            debit: 0,
            credit: $tax,
            description: "Reversión de IVA {$taxRate->percentage}%",
            taxRateId: $taxRate->id,
            taxableBase: $base,
            frozenExchangeRate: $rate,
        );
    }

    private function priceDifferenceLine(
        Company $company,
        DocumentType $documentType,
        InventoryDocument $receipt,
        string $difference,
        string $rate,
    ): JournalLineInput {
        $firstLine = $receipt->lines->first();
        $item = $firstLine->item()->withoutGlobalScope(CompanyScope::class)->first();

        $isLoss = bccomp($difference, '0.00', 2) > 0;

        $account = $this->glResolver->resolve(
            $this->glResolver->load($company),
            'price_difference',
            $item,
            $firstLine->warehouse,
            $documentType,
            $isLoss ? 'debit' : 'credit',
        );

        $amount = $isLoss ? $difference : bcmul($difference, '-1', 2);

        return new JournalLineInput(
            accountId: $account['account_id'],
            currencyId: $company->local_currency_id,
            debit: $isLoss ? $amount : 0,
            credit: $isLoss ? 0 : $amount,
            description: 'Diferencia entre el costo devuelto y lo acreditado',
            costAllocationRuleId: $account['cost_allocation_rule_id'],
            frozenExchangeRate: $rate,
        );
    }

    private function openItemOf(InventoryDocument $receipt): ?BpOpenItem
    {
        $invoice = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->with('details')
            ->find($receipt->invoice_journal_entry_id);

        if (! $invoice) {
            return null;
        }

        foreach ($invoice->details as $detail) {
            $openItem = BpOpenItem::where('origin_journal_detail_id', $detail->id)->first();

            if ($openItem) {
                return $openItem;
            }
        }

        return null;
    }

    private function trim(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
