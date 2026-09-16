<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\MissingExchangeRateException;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Exceptions\InvalidProductionException;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ProductionOrder;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta el ciclo de una orden de fabricación sobre el motor de stock que ya
 * existe. No duplica costeo ni kardex: traduce cada paso a un movimiento.
 *
 *   Emisión:  Debe WIP           / Haber Inventario materia prima
 *   Recibo:   Debe Inventario PT / Haber WIP
 *   Cierre:   Debe Desviación    / Haber WIP   (o al revés si sobró crédito)
 *
 * El costo del producto terminado NO se digita: es el WIP acumulado dividido
 * entre las unidades que ingresan. Por eso un recibo descarga el WIP completo
 * y lo deja en cero — la desviación de fabricación aparece solo al cerrar una
 * orden que quedó con saldo (materia prima consumida que nunca se convirtió en
 * producto: merma, lote fallido).
 */
class PostProductionService
{
    public function __construct(
        private readonly PostStockMovementService $postStockMovementService,
        private readonly PostJournalService $postJournalService,
        private readonly GlDeterminationResolver $glResolver,
    ) {}

    /**
     * Emite materia prima a la orden. Cada línea sale de su almacén al costo
     * promedio vigente, igual que cualquier salida.
     *
     * @param  StockLineInput[]  $lines
     */
    public function issue(
        Company $company,
        DocumentType $documentType,
        ProductionOrder $order,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        array $lines,
        ?string $description = null,
        ?int $createdBy = null,
    ): InventoryDocument {
        return DB::transaction(function () use ($company, $documentType, $order, $documentDate, $postingDate, $lines, $description, $createdBy) {
            $locked = $this->lockOpenOrder($company, $order);

            return $this->postStockMovementService->post(
                company: $company,
                documentType: $documentType,
                operation: 'production_issue',
                documentDate: $documentDate,
                postingDate: $postingDate,
                lines: $lines,
                description: $description ?? "Emisión a producción — orden #{$locked->id}",
                createdBy: $createdBy,
                productionOrderId: $locked->id,
            );
        });
    }

    /**
     * Ingresa producto terminado descargando TODO el WIP acumulado. El costo
     * unitario sale de ahí, no del usuario: es el costo real de lo que se
     * consumió, que es justamente el punto de tener una cuenta en proceso.
     */
    public function receive(
        Company $company,
        DocumentType $documentType,
        ProductionOrder $order,
        int|float|string $quantity,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        ?string $description = null,
        ?int $createdBy = null,
    ): InventoryDocument {
        $produced = number_format((float) $quantity, 6, '.', '');

        if (bccomp($produced, '0.000000', 6) <= 0) {
            throw new InvalidProductionException('La cantidad producida debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($company, $documentType, $order, $produced, $documentDate, $postingDate, $description, $createdBy) {
            $locked = $this->lockOpenOrder($company, $order);

            $wip = $locked->wipBalance();

            if (bccomp($wip, '0.00', 2) <= 0) {
                throw new InvalidProductionException(
                    'La orden no tiene costo acumulado en proceso; emití materia prima antes de recibir producto terminado.'
                );
            }

            // El costo unitario absorbe el WIP completo, así que la cuenta en
            // proceso queda exactamente en cero tras el recibo.
            $unitCost = bcdiv($wip, $produced, 6);

            $document = $this->postStockMovementService->post(
                company: $company,
                documentType: $documentType,
                operation: 'production_receipt',
                documentDate: $documentDate,
                postingDate: $postingDate,
                lines: [new StockLineInput(
                    itemId: $locked->item_id,
                    warehouseId: $locked->warehouse_id,
                    quantity: $produced,
                    unitCostLocal: $unitCost,
                    description: $description,
                )],
                description: $description ?? "Recibo de producción — orden #{$locked->id}",
                createdBy: $createdBy,
                productionOrderId: $locked->id,
            );

            $locked->update([
                'produced_quantity' => bcadd((string) $locked->produced_quantity, $produced, 6),
            ]);

            return $document;
        });
    }

    /**
     * Cierra la orden. Si quedó saldo en proceso —materia prima que se
     * consumió pero nunca llegó a producto terminado— se descarga contra la
     * cuenta de desviación de fabricación: dejarlo en WIP sería mantener como
     * activo un costo que ya no tiene producto que lo respalde.
     */
    public function close(
        Company $company,
        DocumentType $documentType,
        ProductionOrder $order,
        \DateTimeInterface $postingDate,
        ?string $description = null,
        ?int $createdBy = null,
    ): ProductionOrder {
        return DB::transaction(function () use ($company, $documentType, $order, $postingDate, $description, $createdBy) {
            $locked = $this->lockOpenOrder($company, $order);

            $wip = $locked->wipBalance();
            $entryId = null;

            if (bccomp($wip, '0.00', 2) !== 0) {
                $entryId = $this->postVariance($company, $documentType, $locked, $wip, $postingDate, $description, $createdBy)->id;
            }

            $locked->update([
                'status' => 'closed',
                'closed_at' => now(),
                'variance_journal_entry_id' => $entryId,
            ]);

            return $locked;
        });
    }

    private function postVariance(
        Company $company,
        DocumentType $documentType,
        ProductionOrder $order,
        string $wip,
        \DateTimeInterface $postingDate,
        ?string $description,
        ?int $createdBy,
    ) {
        $item = Item::withoutGlobalScope(CompanyScope::class)->findOrFail($order->item_id);
        $warehouse = Warehouse::withoutGlobalScope(CompanyScope::class)->findOrFail($order->warehouse_id);

        $rules = $this->glResolver->load($company);
        $rate = $this->rateOnOrBefore($company, $postingDate);

        // WIP positivo = costo que sobró sin convertirse en producto: se
        // descarga acreditando WIP y debitando la desviación. Negativo (se
        // recibió más de lo emitido) invierte ambos lados.
        $isLoss = bccomp($wip, '0.00', 2) > 0;
        $amount = $isLoss ? $wip : bcmul($wip, '-1', 2);

        $wipAccount = $this->glResolver->resolve($rules, 'wip', $item, $warehouse, $documentType, $isLoss ? 'credit' : 'debit');
        $varianceAccount = $this->glResolver->resolve($rules, 'production_variance', $item, $warehouse, $documentType, $isLoss ? 'debit' : 'credit');

        $label = $description ?? "Desviación de fabricación — orden #{$order->id}";

        return $this->postJournalService->post(
            company: $company,
            documentType: $documentType,
            documentDate: $postingDate,
            postingDate: $postingDate,
            lines: [
                new JournalLineInput(
                    accountId: $varianceAccount['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: $isLoss ? $amount : 0,
                    credit: $isLoss ? 0 : $amount,
                    description: $label,
                    costAllocationRuleId: $varianceAccount['cost_allocation_rule_id'],
                    frozenExchangeRate: $rate,
                ),
                new JournalLineInput(
                    accountId: $wipAccount['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: $isLoss ? 0 : $amount,
                    credit: $isLoss ? $amount : 0,
                    description: $label,
                    costAllocationRuleId: $wipAccount['cost_allocation_rule_id'],
                    frozenExchangeRate: $rate,
                ),
            ],
            description: $label,
            createdBy: $createdBy,
        );
    }

    /**
     * Toma la orden bajo lock y verifica su estado REAL, no el de la instancia
     * que recibió el llamador: un controlador puede traer un objeto cargado
     * antes de que otra request la cerrara, y confiar en esa copia permitiría
     * emitir materia prima contra una orden ya cerrada. Debe correr dentro de
     * una transacción para que el lock sostenga hasta el final de la operación.
     */
    private function lockOpenOrder(Company $company, ProductionOrder $order): ProductionOrder
    {
        if ($order->company_id !== $company->id) {
            throw new \InvalidArgumentException('La orden de fabricación no pertenece a la compañía indicada.');
        }

        $locked = ProductionOrder::withoutGlobalScope(CompanyScope::class)
            ->whereKey($order->id)
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            throw new InvalidProductionException('La orden de fabricación ya no existe.');
        }

        if ($locked->status !== 'open') {
            throw new InvalidProductionException('La orden de fabricación ya está cerrada.');
        }

        return $locked;
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
}
