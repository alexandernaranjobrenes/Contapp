<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\MissingExchangeRateException;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\Exceptions\InvalidLandedCostException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\LandedCostAllocation;
use App\Domains\Inventory\Models\LandedCostDocument;
use App\Domains\Inventory\Models\StockJournal;
use Illuminate\Support\Facades\DB;

/**
 * Capitaliza flete, aranceles y demás costos de importación sobre una
 * recepción ya contabilizada.
 *
 *   Debe  Inventario           (la parte que sigue en existencia)
 *   Debe  Diferencia de precio (la parte cuya mercancía ya salió)
 *   Haber Cuentas por Pagar    (el transportista/agencia, abre partida)
 *
 * No mueve una sola unidad: solo cambia cuánto vale lo que ya está. Aun así
 * deja fila en el kardex (`direction = 'revaluation'`, cantidad cero), porque
 * si no, el promedio cambiaría sin ningún registro que lo explique.
 *
 * Se acredita directo a la cuenta de control del proveedor, no a una cuenta
 * puente: el caso normal es registrar el costo CUANDO llega su factura. La
 * variante de provisionarlo antes es el mismo patrón que GR/IR y se agregará
 * si aparece la necesidad (por eso `landed_cost_clearing` sigue sin activarse).
 */
class PostLandedCostService
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
        int $businessPartnerId,
        int|float|string $amount,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        ?\DateTimeInterface $dueDate = null,
        ?string $description = null,
        ?int $createdBy = null,
    ): LandedCostDocument {
        if ($receipt->company_id !== $company->id) {
            throw new \InvalidArgumentException('La recepción no pertenece a la compañía indicada.');
        }

        if (! in_array($receipt->operation, ['goods_receipt', 'purchase_receipt'], true)) {
            throw new InvalidLandedCostException('Un costo de importación solo se puede aplicar sobre una entrada de mercancía.');
        }

        if ($receipt->status !== 'posted') {
            throw new InvalidLandedCostException('La recepción está anulada; no admite costos adicionales.');
        }

        $total = number_format((float) $amount, 2, '.', '');

        if (bccomp($total, '0.00', 2) <= 0) {
            throw new InvalidLandedCostException('El costo de importación debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($company, $documentType, $receipt, $businessPartnerId, $total, $documentDate, $postingDate, $dueDate, $description, $createdBy) {
            // El almacén de cada línea lo necesita el resolver de la matriz
            // (es uno de sus niveles de precedencia).
            $receipt->loadMissing('lines.warehouse');

            if ($receipt->lines->isEmpty()) {
                throw new InvalidLandedCostException('La recepción no tiene líneas sobre las que repartir el costo.');
            }

            $supplier = BusinessPartner::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->find($businessPartnerId);

            if (! $supplier) {
                throw new InvalidLandedCostException("El socio de negocio id {$businessPartnerId} no existe en la compañía.");
            }

            $itemIds = $receipt->lines->pluck('item_id')->unique()->values()->all();

            $items = Item::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('id', $itemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Existencia GLOBAL por artículo: el promedio es global, así que
            // el costo capitalizado se diluye sobre todas las unidades que
            // quedan, no solo sobre las del almacén que las recibió.
            $onHand = [];
            foreach (ItemWarehouse::whereIn('item_id', $itemIds)->lockForUpdate()->get() as $row) {
                $onHand[$row->item_id] = bcadd($onHand[$row->item_id] ?? '0.000000', (string) $row->on_hand, 6);
            }

            $rate = $this->rateOnOrBefore($company, $postingDate);
            $rules = $this->glResolver->load($company);

            $lineValues = $receipt->lines
                ->map(fn ($line) => bcmul((string) $line->quantity, (string) $line->unit_cost_local, 6))
                ->all();

            $allocated = $this->splitter->allocateByValue($total, $lineValues);

            $allocations = [];
            $capitalizedByItem = [];
            $debitsByAccount = [];
            $journalLines = [];
            $totalCapitalized = '0.00';
            $totalExpensed = '0.00';

            foreach ($receipt->lines->values() as $index => $line) {
                $item = $items->get($line->item_id)
                    ?? throw new InvalidLandedCostException("El artículo id {$line->item_id} de la recepción ya no existe.");

                $itemOnHand = $onHand[$item->id] ?? '0.000000';

                $split = $this->splitter->split($allocated[$index], (string) $line->quantity, $itemOnHand);

                $allocations[] = [
                    'item' => $item,
                    'warehouse_id' => $line->warehouse_id,
                    'received_quantity' => (string) $line->quantity,
                    'on_hand_quantity' => $itemOnHand,
                    'allocated_amount' => $allocated[$index],
                    'capitalized_amount' => $split['capitalized'],
                    'expensed_amount' => $split['expensed'],
                ];

                $totalCapitalized = bcadd($totalCapitalized, $split['capitalized'], 2);
                $totalExpensed = bcadd($totalExpensed, $split['expensed'], 2);

                if (bccomp($split['capitalized'], '0.00', 2) > 0) {
                    $capitalizedByItem[$item->id] = bcadd(
                        $capitalizedByItem[$item->id] ?? '0.00', $split['capitalized'], 2
                    );

                    $this->accumulate($debitsByAccount, $this->glResolver->resolve(
                        $rules, 'inventory', $item, $line->warehouse, $documentType, 'debit'
                    ), $split['capitalized']);
                }

                if (bccomp($split['expensed'], '0.00', 2) > 0) {
                    $this->accumulate($debitsByAccount, $this->glResolver->resolve(
                        $rules, 'price_difference', $item, $line->warehouse, $documentType, 'debit'
                    ), $split['expensed']);
                }
            }

            // Una línea de asiento por CUENTA, no por artículo. Además de ser
            // la forma normal de un asiento (nadie postea cincuenta líneas a
            // la misma cuenta de inventario), evita un descuadre real: cada
            // línea se convierte a moneda extranjera por separado y redondea a
            // dos decimales, así que la suma de las partes redondeadas puede
            // no dar el total redondeado. El detalle por artículo vive en
            // landed_cost_allocations y en el kardex, que es su lugar.
            foreach ($debitsByAccount as $debit) {
                $journalLines[] = new JournalLineInput(
                    accountId: $debit['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: $debit['amount'],
                    credit: 0,
                    description: $description ?? 'Costos de importación',
                    costAllocationRuleId: $debit['cost_allocation_rule_id'],
                    frozenExchangeRate: $rate,
                );
            }

            $journalLines[] = new JournalLineInput(
                accountId: $supplier->gl_account_id,
                currencyId: $company->local_currency_id,
                debit: 0,
                credit: $total,
                description: $description ?? "Costos de importación — {$supplier->name}",
                businessPartnerId: $supplier->id,
                dueDate: $dueDate?->format('Y-m-d'),
                opensItem: true,
                frozenExchangeRate: $rate,
            );

            $entry = $this->postJournalService->post(
                company: $company,
                documentType: $documentType,
                documentDate: $documentDate,
                postingDate: $postingDate,
                lines: $journalLines,
                description: $description ?? 'Costos de importación',
                createdBy: $createdBy,
                dueDate: $dueDate,
            );

            $document = LandedCostDocument::create([
                'company_id' => $company->id,
                'document_type_id' => $documentType->id,
                'journal_entry_id' => $entry->id,
                'inventory_document_id' => $receipt->id,
                'business_partner_id' => $supplier->id,
                'document_date' => $documentDate->format('Y-m-d'),
                'posting_date' => $postingDate->format('Y-m-d'),
                'amount' => $total,
                'capitalized_amount' => $totalCapitalized,
                'expensed_amount' => $totalExpensed,
                'description' => $description,
                'status' => 'posted',
                'created_by' => $createdBy,
            ]);

            // El promedio sube por artículo, no por línea: el costo
            // capitalizado se reparte entre TODAS las unidades que quedan.
            $newAverages = [];

            foreach ($capitalizedByItem as $itemId => $capitalized) {
                $item = $items->get($itemId);
                $quantity = $onHand[$itemId];

                $capitalizedForeign = $this->money(bcdiv($capitalized, $rate, 10));

                $newAverages[$itemId] = [
                    'local' => bcadd((string) $item->avg_cost_local, bcdiv($capitalized, $quantity, 6), 6),
                    'foreign' => bcadd((string) $item->avg_cost_foreign, bcdiv($capitalizedForeign, $quantity, 6), 6),
                ];

                $item->update([
                    'avg_cost_local' => $newAverages[$itemId]['local'],
                    'avg_cost_foreign' => $newAverages[$itemId]['foreign'],
                ]);
            }

            foreach ($allocations as $allocation) {
                $item = $allocation['item'];

                $row = LandedCostAllocation::create([
                    'landed_cost_document_id' => $document->id,
                    'item_id' => $item->id,
                    'warehouse_id' => $allocation['warehouse_id'],
                    'received_quantity' => $allocation['received_quantity'],
                    'on_hand_quantity' => $allocation['on_hand_quantity'],
                    'allocated_amount' => $allocation['allocated_amount'],
                    'capitalized_amount' => $allocation['capitalized_amount'],
                    'expensed_amount' => $allocation['expensed_amount'],
                ]);

                // Solo lo capitalizado cambia el valor del inventario; lo que
                // fue a resultados no tiene nada que registrar en el kardex.
                if (bccomp($allocation['capitalized_amount'], '0.00', 2) <= 0) {
                    continue;
                }

                $average = $newAverages[$item->id];

                StockJournal::create([
                    'company_id' => $company->id,
                    'item_id' => $item->id,
                    'warehouse_id' => $allocation['warehouse_id'],
                    'landed_cost_allocation_id' => $row->id,
                    'journal_entry_id' => $entry->id,
                    'posting_date' => $postingDate->format('Y-m-d'),
                    'direction' => 'revaluation',
                    'quantity' => '0.000000',
                    'unit_cost_local' => '0.000000',
                    'unit_cost_foreign' => '0.000000',
                    'total_cost_local' => $allocation['capitalized_amount'],
                    'total_cost_foreign' => $this->money(bcdiv($allocation['capitalized_amount'], $rate, 10)),
                    'balance_quantity' => $allocation['on_hand_quantity'],
                    'avg_cost_local_after' => $average['local'],
                    'avg_cost_foreign_after' => $average['foreign'],
                    'created_by' => $createdBy,
                ]);
            }

            return $document->load('allocations');
        });
    }

    /**
     * @param  array{account_id: int, cost_allocation_rule_id: int|null}  $resolved
     */
    private function accumulate(array &$debits, array $resolved, string $amount): void
    {
        // La norma de reparto forma parte de la clave: dos artículos que van a
        // la misma cuenta pero con normas distintas no se pueden sumar, porque
        // cada una explota la línea en centros de costo diferentes.
        $key = $resolved['account_id'].':'.($resolved['cost_allocation_rule_id'] ?? '');

        $debits[$key] ??= [
            'account_id' => $resolved['account_id'],
            'cost_allocation_rule_id' => $resolved['cost_allocation_rule_id'],
            'amount' => '0.00',
        ];

        $debits[$key]['amount'] = bcadd($debits[$key]['amount'], $amount, 2);
    }

    private function rateOnOrBefore(Company $company, \DateTimeInterface $date): string
    {
        $rate = ExchangeRate::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('currency_id', $company->foreign_currency_id)
            ->whereDate('rate_date', '<=', $date->format('Y-m-d'))
            ->orderByDesc('rate_date')
            ->first();

        if (! $rate) {
            throw new MissingExchangeRateException(
                "No hay tipo de cambio registrado para la moneda extranjera en o antes de {$date->format('Y-m-d')}."
            );
        }

        return (string) $rate->rate;
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
