<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\Exceptions\InvalidSupplierInvoiceException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\StockJournal;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Support\Facades\DB;

/**
 * Factura de proveedor sobre una entrada por compra previa. No mueve stock:
 * convierte el pasivo provisional de la cuenta puente GR/IR —creado al
 * recibir la mercancía— en la deuda real con el proveedor.
 *
 *   Debe  GR/IR        (cancela la provisión, al mismo monto de la recepción)
 *   Debe  IVA          (crédito fiscal, si la factura lo trae)
 *   Haber Cuentas por Pagar  (cuenta de control del proveedor, abre partida)
 *
 * Las líneas de GR/IR se ESPEJAN del asiento de la recepción —mismas cuentas,
 * mismos montos, mismo tipo de cambio congelado— en vez de volver a resolver
 * la matriz de determinación: si alguien la reconfiguró entre la recepción y
 * la factura, re-resolverla debitaría una cuenta distinta y la puente nunca
 * cerraría en cero.
 */
class PostSupplierInvoiceService
{
    public function __construct(
        private readonly PostJournalService $postJournalService,
        private readonly GlDeterminationResolver $glResolver,
        private readonly StockRevaluationSplitter $splitter,
    ) {}

    public function post(
        Company $company,
        DocumentType $documentType,
        InventoryDocument $receipt,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        ?int $taxAccountId = null,
        int|float|string $taxAmount = 0,
        ?\DateTimeInterface $dueDate = null,
        ?string $description = null,
        ?int $createdBy = null,
        int|float|string|null $netAmount = null,
    ): JournalEntry {
        if ($receipt->company_id !== $company->id) {
            throw new \InvalidArgumentException('La recepción no pertenece a la compañía indicada.');
        }

        if ($receipt->operation !== 'purchase_receipt') {
            throw new InvalidSupplierInvoiceException('Solo una entrada por compra puede facturarse: las demás no generan cuenta puente.');
        }

        if ($receipt->status !== 'posted') {
            throw new InvalidSupplierInvoiceException('La recepción está anulada; no se puede facturar.');
        }

        if ($receipt->invoice_journal_entry_id !== null) {
            throw new InvalidSupplierInvoiceException('Esta recepción ya fue facturada.');
        }

        $tax = number_format((float) $taxAmount, 2, '.', '');

        if ($taxAccountId === null && bccomp($tax, '0.00', 2) !== 0) {
            throw new InvalidSupplierInvoiceException('Un monto de impuesto requiere indicar la cuenta de IVA que lo recibe.');
        }

        $invoicedNet = $netAmount === null ? null : number_format((float) $netAmount, 2, '.', '');

        if ($invoicedNet !== null && bccomp($invoicedNet, '0.00', 2) <= 0) {
            throw new InvalidSupplierInvoiceException('El neto facturado debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($company, $documentType, $receipt, $documentDate, $postingDate, $taxAccountId, $tax, $dueDate, $description, $createdBy, $invoicedNet) {
            // Lock sobre la recepción: dos facturas simultáneas sobre la misma
            // entrada duplicarían la deuda con el proveedor.
            $locked = InventoryDocument::withoutGlobalScope(CompanyScope::class)
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->first();

            if ($locked->invoice_journal_entry_id !== null) {
                throw new InvalidSupplierInvoiceException('Esta recepción ya fue facturada.');
            }

            $supplier = BusinessPartner::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->find($locked->business_partner_id);

            if (! $supplier) {
                throw new InvalidSupplierInvoiceException('La recepción no tiene un proveedor válido asociado.');
            }

            $receiptEntry = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->with('details')
                ->findOrFail($locked->journal_entry_id);

            $lines = [];
            $net = '0.00';

            // En una entrada por compra, el lado acreedor del asiento ES la
            // cuenta puente (el débito es inventario). Espejarlo garantiza que
            // GR/IR cierre exactamente en cero, en las tres monedas.
            foreach ($receiptEntry->details as $detail) {
                if (bccomp((string) $detail->credit_local, '0.00', 2) <= 0) {
                    continue;
                }

                $lines[] = new JournalLineInput(
                    accountId: $detail->account_id,
                    currencyId: $detail->currency_id,
                    debit: $detail->credit_local,
                    credit: 0,
                    description: $detail->description,
                    frozenExchangeRate: $detail->exchange_rate_lc_fc,
                );

                $net = bcadd($net, (string) $detail->credit_local, 2);
            }

            if (empty($lines)) {
                throw new InvalidSupplierInvoiceException('El asiento de la recepción no tiene cuenta puente que liquidar.');
            }

            // Todo el asiento usa el MISMO tipo de cambio congelado de la
            // recepción, y no el vigente al facturar. Así cuadra en las tres
            // monedas sin inventar una cuenta de diferencial cambiario: la
            // deuda queda registrada al valor con el que entró la mercancía, y
            // si el tipo de cambio se movió, esa diferencia la reconoce el
            // proceso de diferencial cambiario sobre el saldo de CxP, que es
            // el módulo dueño de esa responsabilidad.
            $rate = (string) $receiptEntry->details->first()->exchange_rate_lc_fc;

            // Diferencia de precio: el proveedor factura distinto a lo que se
            // recibió. Se reparte con el MISMO criterio que un costo de
            // importación (capitaliza lo que sigue en existencia, el resto va
            // a resultados) — ver StockRevaluationSplitter. La diferencia se
            // valúa al tipo de cambio de la RECEPCIÓN, no al de hoy: no es un
            // costo nuevo, es una corrección del precio de esa misma compra.
            $variance = $invoicedNet === null ? '0.00' : bcsub($invoicedNet, $net, 2);

            $plan = bccomp($variance, '0.00', 2) === 0
                ? ['journalLines' => [], 'revaluations' => []]
                : $this->planPriceVariance($company, $documentType, $locked, $variance, $rate);

            $lines = [...$lines, ...$plan['journalLines']];
            $total = bcadd($invoicedNet ?? $net, $tax, 2);

            if (bccomp($tax, '0.00', 2) > 0) {
                // La tarifa se DERIVA de la cuenta elegida, no se pide aparte:
                // es el mismo mecanismo que ya usa el asiento manual desde el
                // 2026-08-19 (chart_of_accounts.tax_rate_id). PostJournalService
                // valida después que el monto corresponda a base × tarifa.
                $taxAccount = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->id)
                    ->find($taxAccountId);

                if (! $taxAccount) {
                    throw new InvalidSupplierInvoiceException("La cuenta de IVA id {$taxAccountId} no existe en la compañía.");
                }

                if (! $taxAccount->tax_rate_id) {
                    throw new InvalidSupplierInvoiceException(
                        "La cuenta {$taxAccount->code} ({$taxAccount->description_es}) no tiene un indicador de impuesto vinculado; ".
                        'no puede recibir el IVA de una factura.'
                    );
                }

                $taxRate = TaxRate::findOrFail($taxAccount->tax_rate_id);

                $lines[] = new JournalLineInput(
                    accountId: $taxAccount->id,
                    currencyId: $company->local_currency_id,
                    debit: $tax,
                    credit: 0,
                    description: "IVA {$taxRate->percentage}%",
                    taxRateId: $taxRate->id,
                    taxableBase: $net,
                    frozenExchangeRate: $rate,
                );
            }

            $lines[] = new JournalLineInput(
                accountId: $supplier->gl_account_id,
                currencyId: $company->local_currency_id,
                debit: 0,
                credit: $total,
                description: $description ?? "Factura de {$supplier->name}",
                businessPartnerId: $supplier->id,
                dueDate: $dueDate?->format('Y-m-d'),
                // Abre partida pendiente en CxP: sin esto la deuda quedaría
                // contabilizada pero invisible para antigüedad de saldos y sin
                // forma de aplicarle un pago.
                opensItem: true,
                frozenExchangeRate: $rate,
            );

            $entry = $this->postJournalService->post(
                company: $company,
                documentType: $documentType,
                documentDate: $documentDate,
                postingDate: $postingDate,
                lines: $lines,
                description: $description ?? "Factura de compra — {$supplier->name}",
                createdBy: $createdBy,
                dueDate: $dueDate,
            );

            $this->writeRevaluations($company, $plan['revaluations'], $entry, $postingDate, $createdBy);

            $locked->update(['invoice_journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /**
     * Reparte la diferencia entre lo facturado y lo recibido: capitaliza la
     * parte cuya mercancía sigue en existencia y manda el resto a diferencia
     * de precio. Devuelve las líneas de asiento y lo que habrá que escribir en
     * el kardex una vez que el asiento exista.
     *
     * Las líneas se agregan por CUENTA, no por artículo: cada una se convierte
     * a moneda extranjera por separado y redondea, así que una línea por
     * artículo puede descuadrar el asiento por céntimos (mismo criterio que
     * PostLandedCostService).
     */
    private function planPriceVariance(
        Company $company,
        DocumentType $documentType,
        InventoryDocument $receipt,
        string $variance,
        string $rate,
    ): array {
        $receipt->loadMissing('lines.warehouse');

        $isIncrease = bccomp($variance, '0.00', 2) > 0;
        $magnitude = $isIncrease ? $variance : bcmul($variance, '-1', 2);

        $itemIds = $receipt->lines->pluck('item_id')->unique()->values()->all();

        $items = Item::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('id', $itemIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $onHand = [];
        foreach (ItemWarehouse::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
            $onHand[$row->item_id] = bcadd($onHand[$row->item_id] ?? '0.000000', (string) $row->on_hand, 6);
        }

        $lineValues = $receipt->lines
            ->map(fn ($line) => bcmul((string) $line->quantity, (string) $line->unit_cost_local, 6))
            ->all();

        $allocated = $this->splitter->allocateByValue($magnitude, $lineValues);

        $rules = $this->glResolver->load($company);
        $debitsByAccount = [];
        $capitalizedByItem = [];
        $revaluationLines = [];

        foreach ($receipt->lines->values() as $index => $line) {
            $item = $items->get($line->item_id);
            $itemOnHand = $onHand[$item->id] ?? '0.000000';

            $split = $this->splitter->split($allocated[$index], (string) $line->quantity, $itemOnHand);

            if (bccomp($split['capitalized'], '0.00', 2) > 0) {
                $capitalizedByItem[$item->id] = bcadd(
                    $capitalizedByItem[$item->id] ?? '0.00', $split['capitalized'], 2
                );

                $this->accumulate($debitsByAccount, $this->glResolver->resolve(
                    $rules, 'inventory', $item, $line->warehouse, $documentType, $isIncrease ? 'debit' : 'credit'
                ), $split['capitalized']);

                $revaluationLines[] = [
                    'line' => $line,
                    'item' => $item,
                    'amount' => $split['capitalized'],
                    'on_hand' => $itemOnHand,
                ];
            }

            if (bccomp($split['expensed'], '0.00', 2) > 0) {
                $this->accumulate($debitsByAccount, $this->glResolver->resolve(
                    $rules, 'price_difference', $item, $line->warehouse, $documentType, $isIncrease ? 'debit' : 'credit'
                ), $split['expensed']);
            }
        }

        $newAverages = [];

        foreach ($capitalizedByItem as $itemId => $capitalized) {
            $item = $items->get($itemId);
            $quantity = $onHand[$itemId];

            $perUnit = bcdiv($capitalized, $quantity, 6);
            $perUnitForeign = bcdiv($this->money(bcdiv($capitalized, $rate, 10)), $quantity, 6);

            $local = $isIncrease
                ? bcadd((string) $item->avg_cost_local, $perUnit, 6)
                : bcsub((string) $item->avg_cost_local, $perUnit, 6);

            if (bccomp($local, '0.000000', 6) < 0) {
                throw new InvalidSupplierInvoiceException(
                    "La diferencia de precio dejaría el costo promedio de {$item->code} en negativo; revisá el neto facturado."
                );
            }

            $newAverages[$itemId] = [
                'local' => $local,
                'foreign' => $isIncrease
                    ? bcadd((string) $item->avg_cost_foreign, $perUnitForeign, 6)
                    : bcsub((string) $item->avg_cost_foreign, $perUnitForeign, 6),
            ];

            $item->update([
                'avg_cost_local' => $newAverages[$itemId]['local'],
                'avg_cost_foreign' => $newAverages[$itemId]['foreign'],
            ]);
        }

        $journalLines = [];

        foreach ($debitsByAccount as $entry) {
            $journalLines[] = new JournalLineInput(
                accountId: $entry['account_id'],
                currencyId: $company->local_currency_id,
                debit: $isIncrease ? $entry['amount'] : 0,
                credit: $isIncrease ? 0 : $entry['amount'],
                description: 'Diferencia de precio de compra',
                costAllocationRuleId: $entry['cost_allocation_rule_id'],
                frozenExchangeRate: $rate,
            );
        }

        foreach ($revaluationLines as $index => $revaluation) {
            $revaluationLines[$index]['average'] = $newAverages[$revaluation['item']->id];
            $revaluationLines[$index]['is_increase'] = $isIncrease;
            $revaluationLines[$index]['rate'] = $rate;
        }

        return ['journalLines' => $journalLines, 'revaluations' => $revaluationLines];
    }

    /**
     * Una revaluación por diferencia de precio apunta a la LÍNEA de la
     * recepción que revalúa (no a un reparto de costo de importación): es la
     * misma compra, corregida.
     */
    private function writeRevaluations(
        Company $company,
        array $revaluations,
        JournalEntry $entry,
        \DateTimeInterface $postingDate,
        ?int $createdBy,
    ): void {
        foreach ($revaluations as $revaluation) {
            StockJournal::create([
                'company_id' => $company->id,
                'item_id' => $revaluation['item']->id,
                'warehouse_id' => $revaluation['line']->warehouse_id,
                'inventory_document_line_id' => $revaluation['line']->id,
                'journal_entry_id' => $entry->id,
                'posting_date' => $postingDate->format('Y-m-d'),
                'direction' => 'revaluation',
                'quantity' => '0.000000',
                'unit_cost_local' => '0.000000',
                'unit_cost_foreign' => '0.000000',
                // Una baja de valor se guarda con signo negativo: el kardex
                // tiene que poder sumarse para reconstruir el valor del stock.
                'total_cost_local' => $revaluation['is_increase']
                    ? $revaluation['amount']
                    : bcmul($revaluation['amount'], '-1', 2),
                'total_cost_foreign' => $this->money(bcdiv(
                    $revaluation['is_increase'] ? $revaluation['amount'] : bcmul($revaluation['amount'], '-1', 2),
                    $revaluation['rate'], 10
                )),
                'balance_quantity' => $revaluation['on_hand'],
                'avg_cost_local_after' => $revaluation['average']['local'],
                'avg_cost_foreign_after' => $revaluation['average']['foreign'],
                'created_by' => $createdBy,
            ]);
        }
    }

    /**
     * @param  array{account_id: int, cost_allocation_rule_id: int|null}  $resolved
     */
    private function accumulate(array &$debits, array $resolved, string $amount): void
    {
        $key = $resolved['account_id'].':'.($resolved['cost_allocation_rule_id'] ?? '');

        $debits[$key] ??= [
            'account_id' => $resolved['account_id'],
            'cost_allocation_rule_id' => $resolved['cost_allocation_rule_id'],
            'amount' => '0.00',
        ];

        $debits[$key]['amount'] = bcadd($debits[$key]['amount'], $amount, 2);
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
