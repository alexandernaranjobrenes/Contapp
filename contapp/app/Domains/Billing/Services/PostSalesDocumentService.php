<?php

namespace App\Domains\Billing\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Billing\DataTransferObjects\SalesDocumentInput;
use App\Domains\Billing\DataTransferObjects\SalesLineInput;
use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Models\BillingPaymentAccount;
use App\Domains\Billing\Models\BillingTaxAccount;
use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Models\SalesDocumentLine;
use App\Domains\Billing\Models\SalesOrder;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\DataTransferObjects\StockLineInput;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\InventoryDocumentLine;
use App\Domains\Inventory\Services\PostStockMovementService;
use Illuminate\Support\Facades\DB;

/**
 * Emite un comprobante de venta y lo integra con el resto del ERP en una sola
 * transacción ACID:
 *
 *   1. Calcula el resumen fiscal y genera consecutivo y clave numérica.
 *   2. Rebaja el inventario al costo promedio, llevándolo a Costo de Ventas
 *      (reutiliza PostStockMovementService — el kardex tiene un solo dueño).
 *   3. Contabiliza la venta (reutiliza PostJournalService — los asientos
 *      también tienen un solo dueño).
 *
 * Si cualquiera de los tres falla, no queda nada: ni comprobante, ni salida de
 * stock, ni asiento. Servicios llamándose entre sí dentro de una transacción,
 * no eventos — mismo criterio que todo el módulo de inventario.
 *
 * Una NOTA DE CRÉDITO (tipo 03) recorre el mismo camino al revés: reingresa la
 * mercancía al costo con que salió, debita el ingreso y el IVA que la venta
 * acreditó, y cancela la partida de CxC que aquella abrió. Necesita saber qué
 * comprobante corrige (originalSalesDocumentId): sin eso no hay costo al que
 * devolver ni partida que cancelar.
 *
 * El XML, la firma y el envío a Hacienda NO ocurren acá: son una fase aparte
 * que consume este documento ya emitido.
 */
class PostSalesDocumentService
{
    public function __construct(
        private readonly SalesTotalsCalculator $calculator,
        private readonly FiscalKeyGenerator $keyGenerator,
        private readonly PostStockMovementService $postStockMovementService,
        private readonly PostJournalService $postJournalService,
        private readonly SalesOrderService $salesOrderService,
    ) {}

    public function post(Company $company, SalesDocumentInput $input, ?int $createdBy = null): SalesDocument
    {
        $this->assertDocumentIsIssuable($company, $input);

        return DB::transaction(function () use ($company, $input, $createdBy) {
            $documentType = DocumentType::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->findOrFail($input->documentTypeId);

            $partner = $this->resolvePartner($company, $input);
            $activity = $this->resolveActivity($company, $input);

            $computed = $this->calculator->calculate($input->lines);
            $totals = $computed['totals'];

            $this->assertPaymentsMatchTotal($input, $totals['total_document']);

            $document = $this->createDocument($company, $documentType, $input, $partner, $activity, $totals, $createdBy);

            $this->persistLines($document, $computed['lines']);
            $this->persistPaymentsAndReferences($document, $input);

            // Antes de rebajar la existencia: la orden tiene que soltar lo que
            // apartó, o su propia reserva haría ver la mercancía como no
            // disponible y la factura se bloquearía a sí misma.
            $this->consumeOrder($company, $input, $document);

            $inventoryDocument = $this->moveStock($company, $documentType, $input, $document, $createdBy);

            $entry = $this->postSale($company, $documentType, $input, $document, $partner, $activity, $computed, $createdBy);

            $document->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'inventory_document_id' => $inventoryDocument?->id,
            ]);

            return $document->load(['lines.taxes', 'payments', 'references']);
        });
    }

    private function assertDocumentIsIssuable(Company $company, SalesDocumentInput $input): void
    {
        if (empty($input->lines)) {
            throw new InvalidSalesDocumentException('Un comprobante requiere al menos una línea de detalle.');
        }

        if (! array_key_exists($input->fiscalDocumentType, FiscalCatalogs::DOCUMENT_TYPES)) {
            throw new InvalidSalesDocumentException("Tipo de comprobante desconocido: {$input->fiscalDocumentType}.");
        }

        if (! array_key_exists($input->saleCondition, FiscalCatalogs::SALE_CONDITIONS)) {
            throw new InvalidSalesDocumentException("Condición de venta desconocida: {$input->saleCondition}.");
        }

        // El plazo es lo que fija el vencimiento de la partida en CxC: sin él,
        // la venta a crédito quedaría sin fecha de cobro.
        if ($this->isCredit($input->saleCondition) && ($input->creditTermDays === null || $input->creditTermDays < 1)) {
            $label = FiscalCatalogs::SALE_CONDITIONS[$input->saleCondition];

            throw new InvalidSalesDocumentException("La condición de venta \"{$label}\" exige un plazo de crédito en días.");
        }

        if (in_array($input->fiscalDocumentType, FiscalCatalogs::REQUIRE_REFERENCE, true) && empty($input->references)) {
            $label = FiscalCatalogs::DOCUMENT_TYPES[$input->fiscalDocumentType];

            throw new InvalidSalesDocumentException("Un comprobante del tipo \"{$label}\" exige un documento de referencia.");
        }

        // El tiquete electrónico es el único que admite consumidor final sin
        // identificar; el resto respalda crédito fiscal y necesita receptor.
        if ($input->businessPartnerId === null && $input->fiscalDocumentType !== '04') {
            throw new InvalidSalesDocumentException(
                'Solo el tiquete electrónico puede emitirse sin receptor identificado.'
            );
        }

        if (count($input->payments) > FiscalCatalogs::MAX_PAYMENT_METHODS) {
            throw new InvalidSalesDocumentException(
                'Un comprobante admite como máximo '.FiscalCatalogs::MAX_PAYMENT_METHODS.' medios de pago.'
            );
        }

        foreach ($input->payments as $payment) {
            if (! array_key_exists($payment['method_code'], FiscalCatalogs::PAYMENT_METHODS)) {
                throw new InvalidSalesDocumentException("Medio de pago desconocido: {$payment['method_code']}.");
            }
        }

        if ($input->currencyId !== $company->local_currency_id && $input->currencyId !== $company->foreign_currency_id) {
            throw new InvalidSalesDocumentException(
                'La moneda del comprobante debe ser la local o la extranjera configuradas en la compañía.'
            );
        }
    }

    /**
     * La suma de los medios de pago tiene que dar EXACTAMENTE el total. Es una
     * regla de la norma, y además es lo que impide que una venta quede cobrada
     * de menos sin que nadie lo note.
     */
    private function assertPaymentsMatchTotal(SalesDocumentInput $input, string $total): void
    {
        if (empty($input->payments)) {
            return;
        }

        $sum = '0.00000';

        foreach ($input->payments as $payment) {
            $sum = bcadd($sum, number_format((float) $payment['amount'], 5, '.', ''), 5);
        }

        if (bccomp($sum, $total, 2) !== 0) {
            throw new InvalidSalesDocumentException(
                "Los medios de pago suman {$sum} y el comprobante totaliza {$total}; deben coincidir."
            );
        }
    }

    private function resolvePartner(Company $company, SalesDocumentInput $input): ?BusinessPartner
    {
        if ($input->businessPartnerId === null) {
            return null;
        }

        $partner = BusinessPartner::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->find($input->businessPartnerId);

        if (! $partner) {
            throw new InvalidSalesDocumentException("El socio de negocio id {$input->businessPartnerId} no existe en la compañía.");
        }

        if (! in_array($partner->type, ['client', 'both'], true)) {
            throw new InvalidSalesDocumentException(
                "El socio de negocio {$partner->code} ({$partner->name}) no está registrado como cliente."
            );
        }

        return $partner;
    }

    private function resolveActivity(Company $company, SalesDocumentInput $input): CompanyEconomicActivity
    {
        $query = CompanyEconomicActivity::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('status', 'active');

        $activity = $input->emitterActivityCode !== null
            ? (clone $query)->where('code', $input->emitterActivityCode)->first()
            : (clone $query)->where('is_default', true)->first();

        if (! $activity) {
            throw new InvalidSalesDocumentException(
                'No hay actividad económica del emisor configurada para esta venta; sin ella no se sabe a qué cuenta de ingresos va.'
            );
        }

        if (! $activity->revenue_account_id) {
            throw new InvalidSalesDocumentException(
                "La actividad económica {$activity->code} ({$activity->name}) no tiene cuenta de ingresos configurada."
            );
        }

        return $activity;
    }

    private function createDocument(
        Company $company,
        DocumentType $documentType,
        SalesDocumentInput $input,
        ?BusinessPartner $partner,
        CompanyEconomicActivity $activity,
        array $totals,
        ?int $createdBy,
    ): SalesDocument {
        // Lock pesimista sobre el tipo de documento: dos ventas simultáneas no
        // pueden tomar el mismo número fiscal, que además es irrepetible por ley.
        $locked = DocumentType::withoutGlobalScope(CompanyScope::class)
            ->whereKey($documentType->id)
            ->lockForUpdate()
            ->first();

        $next = (int) $locked->next_consecutive;
        $locked->increment('next_consecutive');

        $consecutive = $this->keyGenerator->consecutive(
            $input->branch, $input->terminal, $input->fiscalDocumentType, $next
        );

        $securityCode = $this->keyGenerator->securityCode();

        $clave = $this->keyGenerator->clave(
            $company, $input->documentDate, $consecutive, $input->situation, $securityCode
        );

        $dueDate = $this->isCredit($input->saleCondition)
            ? (clone $input->postingDate)->modify("+{$input->creditTermDays} days")
            : null;

        return SalesDocument::create([
            ...$totals,
            'company_id' => $company->id,
            'document_type_id' => $documentType->id,
            'fiscal_document_type' => $input->fiscalDocumentType,
            'situation' => $input->situation,
            'branch' => $input->branch,
            'terminal' => $input->terminal,
            'consecutive' => $consecutive,
            'clave' => $clave,
            'security_code' => $securityCode,
            'emitter_activity_code' => $activity->code,
            'receiver_activity_code' => $input->receiverActivityCode ?? $partner?->economic_activity_code,
            'business_partner_id' => $partner?->id,
            'currency_id' => $input->currencyId,
            'exchange_rate' => $input->exchangeRate,
            'sale_condition' => $input->saleCondition,
            'credit_term_days' => $input->creditTermDays,
            'document_date' => $input->documentDate->format('Y-m-d'),
            'posting_date' => $input->postingDate->format('Y-m-d'),
            'due_date' => $dueDate?->format('Y-m-d'),
            'notes' => $input->notes,
            'status' => 'draft',
            'original_sales_document_id' => $input->originalSalesDocumentId,
            'created_by' => $createdBy,
        ]);
    }

    private function persistLines(SalesDocument $document, array $lines): void
    {
        foreach ($lines as $computed) {
            /** @var SalesLineInput $line */
            $line = $computed['input'];

            $row = $document->lines()->create([
                'line_number' => $computed['line_number'],
                'item_id' => $line->itemId,
                'warehouse_id' => $line->warehouseId,
                'warehouse_bin_id' => $line->warehouseBinId,
                'item_code' => $line->itemCode,
                'cabys_code' => $line->cabysCode,
                'description' => $line->description,
                'unit_code' => $line->unitCode,
                'is_service' => $line->isService,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'total_amount' => $computed['total_amount'],
                'discount_code' => $line->discountCode,
                'discount_reason' => $line->discountReason,
                'discount_amount' => $line->discountAmount,
                'subtotal' => $computed['subtotal'],
                'tax_amount' => $computed['tax_amount'],
                'exonerated_amount' => $computed['exonerated_amount'],
                'line_total' => $computed['line_total'],
                'vin_or_serial' => $line->vinOrSerial,
            ]);

            foreach ($computed['taxes'] as $tax) {
                $row->taxes()->create($tax);
            }
        }
    }

    private function persistPaymentsAndReferences(SalesDocument $document, SalesDocumentInput $input): void
    {
        foreach ($input->payments as $payment) {
            $document->payments()->create([
                'method_code' => $payment['method_code'],
                'amount' => $payment['amount'],
            ]);
        }

        foreach ($input->references as $reference) {
            $document->references()->create($reference);
        }
    }

    /**
     * Solo las líneas de mercancía con artículo y bodega mueven stock: un
     * servicio no tiene kardex, y una línea de concepto libre tampoco.
     *
     * Una factura saca la mercancía al costo promedio; una nota de crédito la
     * REINGRESA al costo con que salió, que es lo único que deja el costo de
     * ventas cerrado. Por eso la nota necesita saber qué comprobante corrige.
     */
    private function moveStock(
        Company $company,
        DocumentType $documentType,
        SalesDocumentInput $input,
        SalesDocument $document,
        ?int $createdBy,
    ): ?InventoryDocument {
        $isReturn = $input->fiscalDocumentType === SalesDocument::CREDIT_NOTE;
        $merchandise = array_filter(
            $input->lines,
            fn (SalesLineInput $line) => ! $line->isService && $line->itemId !== null && $line->warehouseId !== null
        );

        if (empty($merchandise)) {
            return null;
        }

        $costs = [];
        $returnable = [];

        if ($isReturn) {
            $original = $this->resolveOriginalDocument($company, $input);
            $costs = $this->originalCosts($original);
            $returnable = $this->returnableQuantities($original, $costs);
        }

        $stockLines = [];

        foreach ($merchandise as $line) {
            $key = $line->itemId.':'.$line->warehouseId;
            $unitCost = null;

            if ($isReturn) {
                if (! isset($costs[$key])) {
                    throw new InvalidSalesDocumentException(
                        "La línea \"{$line->description}\" no corresponde a ninguna mercancía del comprobante que se corrige."
                    );
                }

                if (bccomp($line->quantity, $returnable[$key], 6) > 0) {
                    throw new InvalidSalesDocumentException(
                        "No se puede devolver {$line->quantity} de \"{$line->description}\": ".
                        'quedan '.rtrim(rtrim($returnable[$key], '0'), '.').' por devolver del comprobante original.'
                    );
                }

                $returnable[$key] = bcsub($returnable[$key], $line->quantity, 6);
                $unitCost = $costs[$key];
            }

            $stockLines[] = new StockLineInput(
                itemId: $line->itemId,
                warehouseId: $line->warehouseId,
                quantity: $line->quantity,
                unitCostLocal: $unitCost,
                description: $line->description,
                warehouseBinId: $line->warehouseBinId,
            );
        }

        return $this->postStockMovementService->post(
            company: $company,
            documentType: $documentType,
            operation: $isReturn ? 'sales_return' : 'sales_issue',
            documentDate: $input->documentDate,
            postingDate: $input->postingDate,
            lines: $stockLines,
            description: ($isReturn ? 'Devolución de cliente — ' : 'Salida por venta — ').$document->consecutive,
            createdBy: $createdBy,
        );
    }

    /**
     * El comprobante que la nota corrige. Se exige el enlace interno —no basta
     * la referencia fiscal, que es texto libre— porque de ahí salen el costo
     * con que volver a entrar la mercancía y el tope de cuánto se puede
     * devolver.
     */
    private function resolveOriginalDocument(Company $company, SalesDocumentInput $input): SalesDocument
    {
        if ($input->originalSalesDocumentId === null) {
            throw new InvalidSalesDocumentException(
                'Una nota de crédito con líneas de mercancía debe indicar cuál comprobante corrige: '.
                'sin eso no se sabe a qué costo devolver la mercancía al inventario.'
            );
        }

        $original = SalesDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->find($input->originalSalesDocumentId);

        if (! $original || $original->status !== 'posted') {
            throw new InvalidSalesDocumentException('El comprobante que se corrige no existe o no está emitido.');
        }

        if ($original->isCreditNote()) {
            throw new InvalidSalesDocumentException('Una nota de crédito no corrige a otra nota de crédito.');
        }

        if ($original->inventory_document_id === null) {
            throw new InvalidSalesDocumentException(
                'El comprobante que se corrige no movió inventario; una nota sobre él no puede devolver mercancía.'
            );
        }

        return $original;
    }

    /**
     * Libera la reserva de la orden que esta factura cumple y la deja
     * enlazada. Una nota de crédito nunca consume pedido: devuelve mercancía,
     * no la entrega.
     */
    private function consumeOrder(Company $company, SalesDocumentInput $input, SalesDocument $document): void
    {
        if ($input->salesOrderId === null || $input->fiscalDocumentType === SalesDocument::CREDIT_NOTE) {
            return;
        }

        $order = SalesOrder::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->find($input->salesOrderId);

        if (! $order) {
            throw new InvalidSalesDocumentException('La orden de pedido indicada no existe en la compañía.');
        }

        if ($order->business_partner_id !== $document->business_partner_id) {
            throw new InvalidSalesDocumentException(
                'La orden de pedido es de otro cliente; una factura no puede cumplir el pedido de un tercero.'
            );
        }

        $invoiced = [];

        foreach ($input->lines as $line) {
            if ($line->isService || $line->itemId === null || $line->warehouseId === null) {
                continue;
            }

            $key = $line->itemId.':'.$line->warehouseId;

            $invoiced[$key] = [
                'item_id' => $line->itemId,
                'warehouse_id' => $line->warehouseId,
                'quantity' => bcadd($invoiced[$key]['quantity'] ?? '0.000000', $line->quantity, 6),
            ];
        }

        if (empty($invoiced)) {
            throw new InvalidSalesDocumentException(
                'Una factura contra una orden de pedido debe entregar mercancía; solo con servicios no hay pedido que cumplir.'
            );
        }

        $this->salesOrderService->consume($company, $order, array_values($invoiced));

        $document->update(['sales_order_id' => $order->id]);
    }

    /**
     * La partida de CxC que abrió la venta original, para que la nota la
     * cancele en vez de abrir una segunda partida que la contradiga. Devuelve
     * null si la venta fue de contado o si ya no queda partida abierta: en
     * ese caso la nota simplemente acredita la cuenta del cliente.
     */
    private function originalOpenItemId(SalesDocumentInput $input): ?int
    {
        if ($input->originalSalesDocumentId === null) {
            return null;
        }

        $original = SalesDocument::withoutGlobalScope(CompanyScope::class)
            ->find($input->originalSalesDocumentId);

        if (! $original?->journal_entry_id) {
            return null;
        }

        return BpOpenItem::whereHas(
            'originJournalDetail',
            fn ($q) => $q->where('journal_entry_id', $original->journal_entry_id)
        )->value('id');
    }

    /**
     * Costo unitario con que cada artículo salió en la venta original, leído
     * del kardex: es el costo que el asiento de esa venta llevó a resultados.
     *
     * @return array<string, string>
     */
    private function originalCosts(SalesDocument $original): array
    {
        $costs = [];

        $lines = InventoryDocumentLine::whereIn(
            'inventory_document_id', [$original->inventory_document_id]
        )->get();

        foreach ($lines as $line) {
            $costs[$line->item_id.':'.$line->warehouse_id] = (string) $line->unit_cost_local;
        }

        return $costs;
    }

    /**
     * Cuánto queda por devolver de cada artículo: lo vendido menos lo que
     * notas de crédito anteriores ya devolvieron.
     *
     * @param  array<string, string>  $costs
     * @return array<string, string>
     */
    private function returnableQuantities(SalesDocument $original, array $costs): array
    {
        $sold = [];

        foreach ($original->lines()->whereNotNull('item_id')->get() as $line) {
            $key = $line->item_id.':'.$line->warehouse_id;
            $sold[$key] = bcadd($sold[$key] ?? '0.000000', (string) $line->quantity, 6);
        }

        $notes = SalesDocument::withoutGlobalScope(CompanyScope::class)
            ->where('original_sales_document_id', $original->id)
            ->where('status', 'posted')
            ->pluck('id');

        foreach (SalesDocumentLine::whereIn('sales_document_id', $notes)->whereNotNull('item_id')->get() as $line) {
            $key = $line->item_id.':'.$line->warehouse_id;
            $sold[$key] = bcsub($sold[$key] ?? '0.000000', (string) $line->quantity, 6);
        }

        return array_intersect_key($sold, $costs);
    }

    /**
     * Asiento de la venta:
     *   Debe  Cuentas por Cobrar (a crédito, abriendo partida)  o
     *         las cuentas de cada medio de pago (de contado)
     *   Haber Ingresos por ventas de la actividad económica
     *   Haber IVA débito fiscal, desglosado por tarifa
     *
     * El IVA se calcula exacto porque es lo que se le debe a Hacienda; el
     * cobro al cliente también, porque es lo que el cliente debe. El ingreso
     * absorbe la diferencia de redondeo entre los cinco decimales del XML y
     * los dos de la contabilidad — es el único de los tres que puede hacerlo
     * sin distorsionar una obligación con un tercero.
     */
    private function postSale(
        Company $company,
        DocumentType $documentType,
        SalesDocumentInput $input,
        SalesDocument $document,
        ?BusinessPartner $partner,
        CompanyEconomicActivity $activity,
        array $computed,
        ?int $createdBy,
    ) {
        $frozenRate = $input->currencyId === $company->foreign_currency_id
            ? number_format((float) $input->exchangeRate, 6, '.', '')
            : null;

        $totalDocument = $this->money($computed['totals']['total_document']);
        // Solo las tarifas que de verdad generan débito fiscal necesitan
        // cuenta: una línea exenta o exonerada al 100% no acredita nada, y
        // exigirle configuración obligaría a inventar una cuenta que ningún
        // asiento va a usar.
        $taxByRate = array_filter(
            $this->taxByRate($computed['lines']),
            fn (array $amount) => bccomp($this->money($amount['net']), '0.00', 2) > 0
        );

        $taxAccounts = $this->resolveTaxAccounts($company, array_keys($taxByRate));

        // Una nota de crédito es el espejo de la venta: el IVA y el ingreso
        // que aquella acreditó, esta los debita, y el cobro al cliente se
        // convierte en un crédito que cancela la partida abierta.
        $isReturn = $input->fiscalDocumentType === SalesDocument::CREDIT_NOTE;

        $lines = [];
        $totalTaxPosted = '0.00';

        foreach ($taxByRate as $rateCode => $amount) {
            $rounded = $this->money($amount['net']);
            $config = $taxAccounts[$rateCode];
            $exact = bccomp($this->money($amount['gross']), $rounded, 2) === 0;

            $lines[] = new JournalLineInput(
                accountId: $config->account_id,
                currencyId: $input->currencyId,
                debit: $isReturn ? $rounded : 0,
                credit: $isReturn ? 0 : $rounded,
                description: ($isReturn ? 'Reversión de IVA débito fiscal ' : 'IVA débito fiscal ').$rateCode,
                // Solo se enlaza al indicador de impuesto cuando el monto es
                // exactamente base × tarifa. Con exoneración no lo es por
                // definición, y PostJournalService rechazaría el asiento.
                taxRateId: $exact ? $config->tax_rate_id : null,
                taxableBase: $exact ? $this->money($amount['base']) : null,
                frozenExchangeRate: $frozenRate,
            );

            $totalTaxPosted = bcadd($totalTaxPosted, $rounded, 2);
        }

        $revenue = bcsub($totalDocument, $totalTaxPosted, 2);

        $lines[] = new JournalLineInput(
            accountId: $activity->revenue_account_id,
            currencyId: $input->currencyId,
            debit: $isReturn ? $revenue : 0,
            credit: $isReturn ? 0 : $revenue,
            description: ($isReturn ? 'Reversión de ingresos — ' : 'Ingresos — ').$activity->name,
            frozenExchangeRate: $frozenRate,
        );

        foreach ($this->counterpartLines($company, $input, $document, $partner, $totalDocument, $frozenRate) as $line) {
            $lines[] = $line;
        }

        return $this->postJournalService->post(
            company: $company,
            documentType: $documentType,
            documentDate: $input->documentDate,
            postingDate: $input->postingDate,
            lines: $lines,
            description: $input->notes ?? "Venta {$document->consecutive}",
            createdBy: $createdBy,
            dueDate: $document->due_date,
        );
    }

    /**
     * @return JournalLineInput[]
     */
    private function counterpartLines(
        Company $company,
        SalesDocumentInput $input,
        SalesDocument $document,
        ?BusinessPartner $partner,
        string $total,
        ?string $frozenRate,
    ): array {
        $isReturn = $input->fiscalDocumentType === SalesDocument::CREDIT_NOTE;

        if ($this->isCredit($input->saleCondition)) {
            if (! $partner) {
                throw new InvalidSalesDocumentException('Una venta a crédito requiere un cliente identificado.');
            }

            // En una nota de crédito la línea del cliente no abre una partida
            // nueva: cancela la que abrió la venta original, que es lo que
            // hace bajar el saldo en antigüedad en vez de dejar dos partidas
            // sueltas que se anulan entre sí.
            $appliesTo = $isReturn ? $this->originalOpenItemId($input) : null;

            return [new JournalLineInput(
                accountId: $partner->gl_account_id,
                currencyId: $input->currencyId,
                debit: $isReturn ? 0 : $total,
                credit: $isReturn ? $total : 0,
                description: ($isReturn ? 'Nota de crédito — ' : 'Venta a crédito — ').$partner->name,
                businessPartnerId: $partner->id,
                dueDate: $isReturn ? null : $document->due_date?->format('Y-m-d'),
                // Abre la partida en CxC: sin esto la venta quedaría
                // contabilizada pero invisible para antigüedad de saldos.
                opensItem: ! $isReturn,
                applyToOpenItemId: $appliesTo,
                frozenExchangeRate: $frozenRate,
            )];
        }

        if (empty($input->payments)) {
            throw new InvalidSalesDocumentException(
                $isReturn
                    ? 'Una nota de crédito sobre una venta de contado requiere indicar por qué medio se devuelve el dinero.'
                    : 'Una venta de contado requiere indicar con qué medios de pago se cobró.'
            );
        }

        $accounts = BillingPaymentAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->get()
            ->keyBy('method_code');

        $lines = [];
        $assigned = '0.00';
        $last = array_key_last($input->payments);

        foreach ($input->payments as $index => $payment) {
            $config = $accounts->get($payment['method_code']);

            if (! $config) {
                $label = FiscalCatalogs::PAYMENT_METHODS[$payment['method_code']];

                throw new InvalidSalesDocumentException(
                    "No hay cuenta configurada para el medio de pago \"{$label}\"; configurala en el módulo de facturación."
                );
            }

            // El último medio absorbe el redondeo para que el débito sume
            // exactamente el total cobrado.
            $amount = $index === $last
                ? bcsub($total, $assigned, 2)
                : $this->money((string) $payment['amount']);

            $assigned = bcadd($assigned, $amount, 2);

            $lines[] = new JournalLineInput(
                accountId: $config->account_id,
                currencyId: $input->currencyId,
                debit: $isReturn ? 0 : $amount,
                credit: $isReturn ? $amount : 0,
                description: ($isReturn ? 'Devolución por ' : '').FiscalCatalogs::PAYMENT_METHODS[$payment['method_code']],
                frozenExchangeRate: $frozenRate,
            );
        }

        return $lines;
    }

    /**
     * @return array<string, array{base: string, gross: string, net: string}>
     */
    private function taxByRate(array $lines): array
    {
        $byRate = [];

        foreach ($lines as $computed) {
            foreach ($computed['taxes'] as $tax) {
                $code = $tax['iva_rate_code'] ?? $tax['tax_code'];

                $byRate[$code] ??= ['base' => '0.00000', 'gross' => '0.00000', 'net' => '0.00000'];

                $byRate[$code]['base'] = bcadd($byRate[$code]['base'], $tax['taxable_base'], 5);
                $byRate[$code]['gross'] = bcadd($byRate[$code]['gross'], $tax['amount'], 5);
                $byRate[$code]['net'] = bcadd($byRate[$code]['net'], $tax['net_amount'], 5);
            }
        }

        return $byRate;
    }

    /**
     * @param  string[]  $rateCodes
     * @return array<string, BillingTaxAccount>
     */
    private function resolveTaxAccounts(Company $company, array $rateCodes): array
    {
        $configured = BillingTaxAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->get()
            ->keyBy('iva_rate_code');

        $resolved = [];

        foreach ($rateCodes as $code) {
            $config = $configured->get($code);

            if (! $config) {
                $label = FiscalCatalogs::IVA_RATES[$code]['label'] ?? $code;

                throw new InvalidSalesDocumentException(
                    "No hay cuenta de IVA débito fiscal configurada para la tarifa \"{$label}\"."
                );
            }

            $resolved[$code] = $config;
        }

        return $resolved;
    }

    private function isCredit(string $saleCondition): bool
    {
        return in_array($saleCondition, FiscalCatalogs::CREDIT_CONDITIONS, true);
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
