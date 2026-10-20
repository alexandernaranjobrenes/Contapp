<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Billing\DataTransferObjects\SalesDocumentInput;
use App\Domains\Billing\DataTransferObjects\SalesLineInput;
use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Exceptions\PriceOverrideNotAuthorizedException;
use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\PriceOverrideAuthorization;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Models\SalesOrder;
use App\Domains\Billing\Services\Hacienda\ElectronicInvoicing;
use App\Domains\Billing\Services\Hacienda\ElectronicPreflight;
use App\Domains\Billing\Services\Hacienda\HaciendaPublicData;
use App\Domains\Billing\Services\PostSalesDocumentService;
use App\Domains\Billing\Services\PriceOverrideAuthorizer;
use App\Domains\Billing\Services\PriceOverrideGuard;
use App\Domains\Billing\Services\SalesDocumentXmlBuilder;
use App\Domains\Billing\Support\EconomicActivityCatalog;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Billing\Support\HaciendaClock;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseBin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesDocumentController extends Controller
{
    public function __construct(
        private readonly PostSalesDocumentService $postSalesDocumentService,
        private readonly SalesDocumentXmlBuilder $xmlBuilder,
        private readonly PriceOverrideGuard $priceOverrideGuard,
        private readonly PriceOverrideAuthorizer $priceOverrideAuthorizer,
        private readonly ElectronicInvoicing $electronic,
        private readonly ElectronicPreflight $preflight,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Billing/Sales/Index', [
            'documents' => SalesDocument::with([
                'businessPartner:id,code,name', 'currency:id,code',
                // Calificadas: latestOfMany se une consigo misma, y sin la tabla
                // las columnas quedan ambiguas.
                'electronicDocument' => fn ($q) => $q->select([
                    'electronic_documents.id', 'electronic_documents.sales_document_id',
                    'electronic_documents.status', 'electronic_documents.environment',
                ]),
            ])
                ->orderByDesc('posting_date')
                ->orderByDesc('id')
                ->limit(200)
                ->get([
                    'id', 'fiscal_document_type', 'consecutive', 'clave', 'business_partner_id',
                    'currency_id', 'sale_condition', 'posting_date', 'due_date',
                    'total_document', 'status', 'journal_entry_id', 'inventory_document_id',
                ]),
            'documentTypeLabels' => FiscalCatalogs::DOCUMENT_TYPES,
            'saleConditionLabels' => FiscalCatalogs::SALE_CONDITIONS,
            'electronicLabels' => ElectronicDocument::LABELS,
        ]);
    }

    /**
     * Los seis paneles de la pantalla de facturación se alimentan de acá.
     * Los catálogos de la norma van como constantes (no son configuración);
     * los datos maestros sí salen de la base.
     */
    public function create(Request $request, CurrentCompany $currentCompany): Response
    {
        return Inertia::render('Billing/Sales/Create', [
            // "Copiar a": la pantalla es la misma, precargada desde el pedido
            // que se va a cumplir o desde el comprobante que se va a corregir.
            'sourceOrder' => $this->sourceOrder($request),
            'sourceDocument' => $this->sourceDocument($request),
            'catalogs' => [
                'documentTypes' => FiscalCatalogs::DOCUMENT_TYPES,
                'identificationTypes' => FiscalCatalogs::IDENTIFICATION_TYPES,
                'saleConditions' => FiscalCatalogs::SALE_CONDITIONS,
                'creditConditions' => FiscalCatalogs::CREDIT_CONDITIONS,
                'paymentMethods' => FiscalCatalogs::PAYMENT_METHODS,
                'maxPaymentMethods' => FiscalCatalogs::MAX_PAYMENT_METHODS,
                'taxCodes' => FiscalCatalogs::TAX_CODES,
                'ivaRates' => FiscalCatalogs::IVA_RATES,
                'units' => FiscalCatalogs::UNITS,
                'discountCodes' => FiscalCatalogs::DISCOUNT_CODES,
                'exonerationDocumentTypes' => FiscalCatalogs::EXONERATION_DOCUMENT_TYPES,
                'exonerationInstitutions' => FiscalCatalogs::EXONERATION_INSTITUTIONS,
                'referenceDocumentTypes' => FiscalCatalogs::REFERENCE_DOCUMENT_TYPES,
                'referenceReasons' => FiscalCatalogs::REFERENCE_REASONS,
                'requireReference' => FiscalCatalogs::REQUIRE_REFERENCE,
                'situations' => FiscalCatalogs::SITUATIONS,
                'saleConditionsNotIn' => FiscalCatalogs::SALE_CONDITIONS_NOT_IN,
            ],
            'documentTypes' => DocumentType::where('origin_module', 'ventas')
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'activities' => CompanyEconomicActivity::where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name', 'is_default']),
            // Las actividades de TRIBU-CR: la del receptor se elige de acá.
            'activityCatalog' => EconomicActivityCatalog::all(),
            'customers' => BusinessPartner::whereIn('type', ['client', 'both'])
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'tax_id', 'identification_type', 'email', 'phone', 'economic_activity_code', 'payment_terms_days']),
            'items' => Item::where('status', 'active')->orderBy('code')
                ->get(['id', 'code', 'name', 'cabys_code', 'fiscal_unit_code', 'iva_rate_code', 'is_inventory_item']),
            'warehouses' => Warehouse::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'uses_bins']),
            'bins' => WarehouseBin::whereIn('warehouse_id', Warehouse::pluck('id'))
                ->where('status', 'active')->orderBy('code')->get(['id', 'warehouse_id', 'code']),
            'currencies' => Currency::orderBy('code')->get(['id', 'code', 'name']),
            // La facturación electrónica de la compañía: si está encendida, en
            // qué ambiente, la sucursal y terminal por defecto, y lo que falta.
            'hacienda' => $this->electronic->status(Company::findOrFail($currentCompany->id())),
            // Si quien está emitiendo ya puede liberar cambios de precio, la
            // pantalla no le pide autorización a nadie. El servidor lo
            // vuelve a comprobar: esto solo evita el trámite visual.
            'canAuthorizePriceChange' => $this->priceOverrideGuard->canAuthorize(
                request()->user(), app(CurrentCompany::class)->id()
            ),
        ]);
    }

    /**
     * Pedido a cumplir, con lo que sigue pendiente de entregar por línea. La
     * pantalla lo usa para armar la factura sin volver a digitar nada.
     */
    private function sourceOrder(Request $request): ?array
    {
        $order = SalesOrder::with(['lines.item:id,code,name,cabys_code,fiscal_unit_code,iva_rate_code', 'businessPartner:id,code,name'])
            ->where('status', 'open')
            ->find($request->integer('order') ?: 0);

        if (! $order) {
            return null;
        }

        return [
            'id' => $order->id,
            'number' => $order->number,
            'business_partner_id' => $order->business_partner_id,
            'customer' => $order->businessPartner?->code.' — '.$order->businessPartner?->name,
            'lines' => $order->lines
                ->filter(fn ($line) => bccomp($line->pending(), '0.000000', 6) > 0)
                ->map(fn ($line) => [
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'item_code' => $line->item?->code,
                    'description' => $line->description ?: $line->item?->name,
                    'cabys_code' => $line->item?->cabys_code,
                    'unit_code' => $line->item?->fiscal_unit_code,
                    'iva_rate_code' => $line->item?->iva_rate_code,
                    'quantity' => (float) $line->pending(),
                    'unit_price' => (float) $line->unit_price,
                ])->values(),
        ];
    }

    /**
     * Comprobante a corregir con una nota de crédito, con lo que todavía se
     * puede devolver de cada línea.
     */
    private function sourceDocument(Request $request): ?array
    {
        $document = SalesDocument::with(['lines.item:id,code,name', 'businessPartner:id,code,name'])
            ->where('status', 'posted')
            ->where('fiscal_document_type', '!=', SalesDocument::CREDIT_NOTE)
            ->find($request->integer('correct') ?: 0);

        if (! $document) {
            return null;
        }

        $returned = [];

        foreach (SalesDocument::where('original_sales_document_id', $document->id)
            ->where('status', 'posted')->with('lines')->get() as $note) {
            foreach ($note->lines as $line) {
                $key = $line->item_id.':'.$line->warehouse_id;
                $returned[$key] = bcadd($returned[$key] ?? '0.000000', (string) $line->quantity, 6);
            }
        }

        return [
            'id' => $document->id,
            'consecutive' => $document->consecutive,
            'clave' => $document->clave,
            'document_date' => $document->document_date->format('Y-m-d'),
            'fiscal_document_type' => $document->fiscal_document_type,
            'business_partner_id' => $document->business_partner_id,
            'customer' => $document->businessPartner?->code.' — '.$document->businessPartner?->name,
            'sale_condition' => $document->sale_condition,
            'credit_term_days' => $document->credit_term_days,
            'currency_id' => $document->currency_id,
            'lines' => $document->lines->map(function ($line) use ($returned) {
                $key = $line->item_id.':'.$line->warehouse_id;
                $pending = $line->item_id
                    ? bcsub((string) $line->quantity, $returned[$key] ?? '0.000000', 6)
                    : (string) $line->quantity;

                return [
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'item_code' => $line->item_code,
                    'description' => $line->description,
                    'cabys_code' => $line->cabys_code,
                    'unit_code' => $line->unit_code,
                    'is_service' => (bool) $line->is_service,
                    'quantity' => (float) $pending,
                    'unit_price' => (float) $line->unit_price,
                ];
            })->filter(fn (array $line) => $line['quantity'] > 0)->values(),
        ];
    }

    public function show(int $salesDocument): Response
    {
        $document = SalesDocument::with([
            'lines.taxes', 'lines.item:id,code,name', 'lines.warehouse:id,code',
            'payments', 'references', 'businessPartner', 'currency:id,code',
            'journalEntry:id,document_number', 'inventoryDocument:id,journal_entry_id',
        ])->findOrFail($salesDocument);

        return Inertia::render('Billing/Sales/Show', [
            'document' => $document,
            'issuedAt' => $document->issued_at ? HaciendaClock::local($document->issued_at)->format('d/m/Y H:i') : null,
            'electronic' => $this->electronicSummary($document),
            'catalogs' => [
                'documentTypes' => FiscalCatalogs::DOCUMENT_TYPES,
                'saleConditions' => FiscalCatalogs::SALE_CONDITIONS,
                'paymentMethods' => FiscalCatalogs::PAYMENT_METHODS,
                'ivaRates' => FiscalCatalogs::IVA_RATES,
                'referenceDocumentTypes' => FiscalCatalogs::REFERENCE_DOCUMENT_TYPES,
            ],
        ]);
    }

    /**
     * Lo que la pantalla muestra del comprobante ante Hacienda: el intento
     * vigente con su historia, y los anteriores que quedaron sustituidos.
     * Nunca el XML (se descarga aparte) ni nada de las credenciales.
     *
     * @return array<string, mixed>|null
     */
    private function electronicSummary(SalesDocument $document): ?array
    {
        $attempts = ElectronicDocument::where('sales_document_id', $document->id)
            ->with(['events.user:id,name'])
            ->orderByDesc('id')
            ->get();

        $current = $attempts->first();

        if ($current === null) {
            return null;
        }

        $time = fn ($value) => $value ? HaciendaClock::local($value)->format('d/m/Y H:i') : null;

        return [
            'id' => $current->id,
            'status' => $current->status,
            'label' => $current->label(),
            'environment' => $current->environment,
            'environment_label' => config("hacienda.environments.{$current->environment}.label"),
            'clave' => $current->clave,
            'consecutive' => $current->consecutive,
            'issued_at' => $time($current->issued_at),
            'submitted_at' => $time($current->submitted_at),
            'accepted_at' => $time($current->accepted_at),
            'rejected_at' => $time($current->rejected_at),
            'last_checked_at' => $time($current->last_checked_at),
            'next_attempt_at' => $time($current->next_attempt_at),
            'hacienda_status' => $current->hacienda_status,
            'hacienda_message' => $current->hacienda_message,
            'has_response' => filled($current->getRawOriginal('response_xml')),
            'response_signature_valid' => $current->response_signature_valid,
            'delivery_status' => $current->delivery_status,
            'delivered_at' => $time($current->delivered_at),
            'delivery_error' => $current->delivery_error,
            'delivery_recipients' => $current->delivery_recipients,
            'can_check' => in_array($current->status, [
                ElectronicDocument::SUBMITTED, ElectronicDocument::SUBMIT_UNKNOWN,
                ElectronicDocument::NEEDS_ATTENTION, ElectronicDocument::SUBMITTING,
            ], true),
            'can_retry' => in_array($current->status, [
                ElectronicDocument::SIGNED, ElectronicDocument::SUBMIT_UNKNOWN, ElectronicDocument::NEEDS_ATTENTION,
            ], true),
            'can_reissue' => $current->canBeReissued(),
            'can_deliver' => $current->status === ElectronicDocument::ACCEPTED,
            'events' => $current->events->sortByDesc('id')->values()->map(fn ($event) => [
                'id' => $event->id,
                'to' => ElectronicDocument::LABELS[$event->to_status] ?? $event->to_status,
                'to_status' => $event->to_status,
                'message' => $event->message,
                'by' => $event->user?->name ?? 'Sistema',
                'at' => $time($event->created_at),
            ]),
            'previous' => $attempts->slice(1)->values()->map(fn (ElectronicDocument $attempt) => [
                'id' => $attempt->id,
                'consecutive' => $attempt->consecutive,
                'clave' => $attempt->clave,
                'label' => $attempt->label(),
                'hacienda_message' => $attempt->hacienda_message,
                'issued_at' => $time($attempt->issued_at),
            ]),
        ];
    }

    /**
     * Descarga el XML del comprobante. Si ya es electrónico, el FIRMADO, tal
     * como se envió a Hacienda: es el documento que vale. Si no, uno sin
     * firmar, para revisarlo o validarlo contra el XSD oficial.
     */
    public function xml(int $salesDocument, CurrentCompany $currentCompany): StreamedResponse
    {
        $document = SalesDocument::findOrFail($salesDocument);
        $electronic = ElectronicDocument::where('sales_document_id', $document->id)->orderByDesc('id')->first();

        $xml = $electronic?->signed_xml
            ?? $this->xmlBuilder->build($document, Company::findOrFail($currentCompany->id()));

        return response()->streamDownload(
            fn () => print ($xml),
            "{$document->clave}.xml",
            ['Content-Type' => 'application/xml'],
        );
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $this->validated($request, $companyId);
        $company = Company::findOrFail($companyId);

        // El precio de la lista se respeta: apartarse de él necesita que lo
        // libere un administrador. Se comprueba ANTES de emitir porque
        // después ya hay comprobante, asiento y movimiento de stock, y
        // deshacerlos por una autorización que faltaba sería corregir con
        // una nota de crédito algo que nunca debió emitirse.
        try {
            $deviations = $this->resolvePriceOverrides($request, $company, $validated);
        } catch (PriceOverrideNotAuthorizedException $e) {
            return back()
                ->withErrors(['price_override' => $e->getMessage()])
                ->withInput();
        }

        // Las líneas y el encabezado validan sus propias reglas al armarse (una
        // mercancía sin bodega, un descuento «Otros» sin explicar): un error
        // ahí vuelve al formulario con su motivo, como los de emitir, y no
        // como una página de error.
        try {
            $input = $this->buildInput($validated);
        } catch (InvalidSalesDocumentException $e) {
            return back()->withErrors(['billing' => $e->getMessage()])->withInput();
        }

        // Con la facturación electrónica encendida, lo que Hacienda ya dijo
        // que va a rechazar (un CAByS que no existe, una actividad que el
        // emisor no tiene inscrita) se avisa acá, antes de gastar el
        // consecutivo en un rechazo seguro.
        if ($this->electronic->settings($company)?->enabled) {
            $problems = $this->preflight->problems($company, $input);

            if ($problems !== []) {
                return back()->withErrors(['billing' => implode(' ', $problems)])->withInput();
            }
        }

        try {
            $document = $this->postSalesDocumentService->post(
                $company, $input, $request->user()->id
            );
        } catch (\RuntimeException $e) {
            // Toda excepción de dominio ya revirtió comprobante, movimiento de
            // stock y asiento: acá solo se traduce a un mensaje de formulario.
            return back()->withErrors(['billing' => $e->getMessage()])->withInput();
        }

        $this->recordPriceOverrides($document, $deviations);

        $message = ElectronicDocument::where('sales_document_id', $document->id)->exists()
            ? "Comprobante {$document->consecutive} emitido, firmado y en camino a Hacienda."
            : "Comprobante {$document->consecutive} emitido y registrado en el ERP.";

        return redirect()
            ->route('sales-documents.show', $document->id)
            ->with('success', $message);
    }

    /**
     * @throws InvalidSalesDocumentException
     */
    private function buildInput(array $validated): SalesDocumentInput
    {
        $lines = array_map(fn (array $line) => new SalesLineInput(
            cabysCode: $line['cabys_code'],
            description: $line['description'],
            unitCode: $line['unit_code'],
            quantity: $line['quantity'],
            unitPrice: $line['unit_price'],
            taxes: array_map(fn (array $tax) => new SalesTaxInput(
                taxCode: $tax['tax_code'] ?? '01',
                ivaRateCode: $tax['iva_rate_code'] ?? null,
                exonerationDocumentType: $tax['exoneration_document_type'] ?? null,
                exonerationDocumentNumber: $tax['exoneration_document_number'] ?? null,
                exonerationArticle: $tax['exoneration_article'] ?? null,
                exonerationClause: $tax['exoneration_clause'] ?? null,
                exonerationInstitution: $tax['exoneration_institution'] ?? null,
                exonerationDate: $tax['exoneration_date'] ?? null,
                exoneratedPercentage: $tax['exonerated_percentage'] ?? null,
            ), $line['taxes'] ?? []),
            itemId: $line['item_id'] ?? null,
            warehouseId: $line['warehouse_id'] ?? null,
            warehouseBinId: $line['warehouse_bin_id'] ?? null,
            itemCode: $line['item_code'] ?? null,
            isService: $line['is_service'] ?? false,
            discountCode: $line['discount_code'] ?? null,
            discountReason: $line['discount_reason'] ?? null,
            discountAmount: $line['discount_amount'] ?? 0,
            vinOrSerial: $line['vin_or_serial'] ?? null,
        ), $validated['lines']);

        $input = new SalesDocumentInput(
            documentTypeId: (int) $validated['document_type_id'],
            fiscalDocumentType: $validated['fiscal_document_type'],
            currencyId: (int) $validated['currency_id'],
            saleCondition: $validated['sale_condition'],
            documentDate: new \DateTimeImmutable($validated['document_date']),
            postingDate: new \DateTimeImmutable($validated['posting_date']),
            lines: $lines,
            exchangeRate: $validated['exchange_rate'] ?? 1,
            businessPartnerId: isset($validated['business_partner_id']) ? (int) $validated['business_partner_id'] : null,
            creditTermDays: $validated['credit_term_days'] ?? null,
            emitterActivityCode: $validated['emitter_activity_code'] ?? null,
            receiverActivityCode: $validated['receiver_activity_code'] ?? null,
            branch: $validated['branch'] ?? '001',
            terminal: $validated['terminal'] ?? '00001',
            situation: $validated['situation'] ?? '1',
            payments: $validated['payments'] ?? [],
            references: $validated['references'] ?? [],
            notes: $validated['notes'] ?? null,
            originalSalesDocumentId: isset($validated['original_sales_document_id'])
                ? (int) $validated['original_sales_document_id'] : null,
            salesOrderId: isset($validated['sales_order_id']) ? (int) $validated['sales_order_id'] : null,
            saleConditionOther: $validated['sale_condition_other'] ?? null,
        );

        return $input;
    }

    /**
     * Detecta las líneas que se apartan del precio de lista y exige que un
     * administrador las libere.
     *
     * Devuelve los desvíos ya autorizados —vacío si la factura respeta los
     * precios— para que se registren DESPUÉS de emitir, cuando ya existen
     * los ids del comprobante y de sus líneas.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PriceOverrideNotAuthorizedException
     */
    private function resolvePriceOverrides(Request $request, Company $company, array $validated): array
    {
        $user = $request->user();

        $deviations = $this->priceOverrideGuard->deviations(
            $company,
            isset($validated['business_partner_id'])
                ? BusinessPartner::find($validated['business_partner_id'])
                : null,
            $validated['document_date'],
            (int) $validated['currency_id'],
            $validated['lines'],
            // El pedido que esta factura cumple: lo que ya se autorizó ahí
            // no se vuelve a pedir. Firmar dos veces el mismo precio
            // convertiría el control en un estorbo.
            isset($validated['sales_order_id']) ? (int) $validated['sales_order_id'] : null,
        );

        if ($deviations === []) {
            return [];
        }

        // Quien ya puede autorizar no se autoriza a sí mismo: pedirle la
        // contraseña que acaba de usar para entrar sería un trámite vacío.
        // Tampoco se registra: no hubo autorización de nadie más, y el
        // comprobante ya dice quién lo emitió.
        if ($this->priceOverrideGuard->canAuthorize($user, $company->id)) {
            return [];
        }

        $email = trim((string) $request->input('price_override_email'));
        $password = (string) $request->input('price_override_password');

        // Sin credenciales el error va bajo 'price_override' y no bajo cada
        // campo: quien emite necesita saber QUÉ pasa —la factura se aparta
        // de la lista— antes que qué casilla le falta. La pantalla abre el
        // modal con el detalle de las líneas.
        if ($email === '' || $password === '') {
            throw new PriceOverrideNotAuthorizedException(sprintf(
                'Esta factura se aparta de la lista de precios en %d línea(s). '.
                'Para emitirla hace falta que la autorice un administrador o el superusuario.',
                count($deviations),
            ));
        }

        $authorizer = $this->priceOverrideAuthorizer->verify(
            $email, $password, $company->id, $user->id,
        );

        $reason = $request->input('price_override_reason');

        return array_map(fn (array $d) => [
            ...$d,
            'requested_by' => $user->id,
            'authorized_by' => $authorizer->id,
            'reason' => is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
        ], $deviations);
    }

    /**
     * @param  array<int, array<string, mixed>>  $deviations
     */
    private function recordPriceOverrides(SalesDocument $document, array $deviations): void
    {
        if ($deviations === []) {
            return;
        }

        // Las líneas del comprobante se numeran desde 1 en el orden en que
        // llegaron, así que el índice base 0 del formulario las ubica.
        $lineIds = $document->lines()->orderBy('line_number')->pluck('id')->all();

        foreach ($deviations as $index => $deviation) {
            PriceOverrideAuthorization::create([
                'company_id' => $document->company_id,
                'sales_document_id' => $document->id,
                'sales_document_line_id' => $lineIds[$index] ?? null,
                'item_id' => $deviation['item_id'],
                'price_list_id' => $deviation['price_list_id'],
                'price_list_code' => $deviation['price_list_code'],
                'list_unit_price' => $deviation['list_unit_price'],
                'invoiced_unit_price' => $deviation['invoiced_unit_price'],
                'difference' => $deviation['difference'],
                'requested_by' => $deviation['requested_by'],
                'authorized_by' => $deviation['authorized_by'],
                'reason' => $deviation['reason'],
            ]);
        }
    }

    private function receiverActivityRule(Request $request): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request) {
            if (! is_string($value) || EconomicActivityCatalog::exists($value)) {
                return;
            }

            $taxId = preg_replace('/\D/', '', (string) BusinessPartner::whereKey($request->input('business_partner_id'))->value('tax_id'));

            if ($taxId === '' || app(HaciendaPublicData::class)->activityIsRegistered($taxId, $value) !== true) {
                $fail("La actividad {$value} no está en el catálogo de actividades de Hacienda: elegila de la lista.");
            }
        };
    }

    private function validated(Request $request, int $companyId): array
    {
        return $request->validate([
            'document_type_id' => [
                'required',
                Rule::exists('document_types', 'id')->where('company_id', $companyId)->where('origin_module', 'ventas'),
            ],
            'fiscal_document_type' => ['required', Rule::in(array_keys(FiscalCatalogs::DOCUMENT_TYPES))],
            'situation' => ['nullable', Rule::in(array_keys(FiscalCatalogs::SITUATIONS))],
            'branch' => ['nullable', 'string', 'size:3'],
            'terminal' => ['nullable', 'string', 'size:5'],
            'business_partner_id' => [
                'nullable',
                Rule::exists('business_partners', 'id')->where('company_id', $companyId),
            ],
            'emitter_activity_code' => ['nullable', 'string', 'size:6'],
            // La actividad inscrita del receptor (CodigoActividadReceptor):
            // del catálogo de TRIBU-CR, o una que Hacienda le tiene inscrita.
            'receiver_activity_code' => ['nullable', 'string', 'size:6', $this->receiverActivityRule($request)],
            'currency_id' => ['required', Rule::exists('currencies', 'id')],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'sale_condition' => ['required', Rule::in(array_keys(FiscalCatalogs::SALE_CONDITIONS))],
            'sale_condition_other' => ['nullable', 'required_if:sale_condition,99', 'string', 'min:5', 'max:100'],
            'credit_term_days' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'document_date' => ['required', 'date'],
            'posting_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],

            // Enlaces internos del ciclo: el pedido que esta factura cumple y
            // el comprobante que una nota de crédito corrige. Que sean
            // coherentes (mismo cliente, mercancía que corresponde) lo valida
            // el servicio, que es quien conoce la regla.
            'sales_order_id' => ['nullable', Rule::exists('sales_orders', 'id')->where('company_id', $companyId)],
            'original_sales_document_id' => [
                'nullable',
                Rule::exists('sales_documents', 'id')->where('company_id', $companyId),
            ],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.cabys_code' => ['required', 'string', 'size:13'],
            'lines.*.description' => ['required', 'string', 'min:3', 'max:200'],
            'lines.*.unit_code' => ['required', Rule::in(array_keys(FiscalCatalogs::UNITS))],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'lines.*.warehouse_bin_id' => ['nullable', 'integer'],
            'lines.*.item_code' => ['nullable', 'string', 'max:255'],
            'lines.*.is_service' => ['boolean'],
            'lines.*.discount_code' => ['nullable', Rule::in(array_keys(FiscalCatalogs::DISCOUNT_CODES))],
            'lines.*.discount_reason' => ['nullable', 'string', 'max:80'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.vin_or_serial' => ['nullable', 'string', 'max:17'],
            'lines.*.taxes' => ['array'],
            'lines.*.taxes.*.tax_code' => ['nullable', Rule::in(array_keys(FiscalCatalogs::TAX_CODES))],
            'lines.*.taxes.*.iva_rate_code' => ['nullable', Rule::in(array_keys(FiscalCatalogs::IVA_RATES))],
            'lines.*.taxes.*.exoneration_document_type' => ['nullable', Rule::in(array_keys(FiscalCatalogs::EXONERATION_DOCUMENT_TYPES))],
            'lines.*.taxes.*.exoneration_document_number' => ['nullable', 'string', 'max:40'],
            'lines.*.taxes.*.exoneration_article' => ['nullable', 'string', 'max:10'],
            'lines.*.taxes.*.exoneration_clause' => ['nullable', 'string', 'max:10'],
            'lines.*.taxes.*.exoneration_institution' => ['nullable', Rule::in(array_keys(FiscalCatalogs::EXONERATION_INSTITUTIONS))],
            'lines.*.taxes.*.exoneration_date' => ['nullable', 'date'],
            'lines.*.taxes.*.exonerated_percentage' => ['nullable', 'numeric', 'between:0,100'],

            'payments' => ['array', 'max:'.FiscalCatalogs::MAX_PAYMENT_METHODS],
            'payments.*.method_code' => ['required', Rule::in(array_keys(FiscalCatalogs::PAYMENT_METHODS))],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.method_other' => ['nullable', 'string', 'min:3', 'max:100'],

            'references' => ['array', 'max:10'],
            'references.*.document_type' => ['required', Rule::in(array_keys(FiscalCatalogs::REFERENCE_DOCUMENT_TYPES))],
            'references.*.number' => ['required', 'string', 'max:50'],
            'references.*.issued_at' => ['nullable', 'date'],
            'references.*.reason_code' => ['required', Rule::in(array_keys(FiscalCatalogs::REFERENCE_REASONS))],
            'references.*.reason' => ['required', 'string', 'min:3', 'max:180'],
        ]);
    }
}
