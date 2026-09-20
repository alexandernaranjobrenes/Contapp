<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Billing\Contracts\HaciendaSigner;
use App\Domains\Billing\Contracts\HaciendaTransport;
use App\Domains\Billing\DataTransferObjects\SalesDocumentInput;
use App\Domains\Billing\DataTransferObjects\SalesLineInput;
use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Models\SalesOrder;
use App\Domains\Billing\Services\PostSalesDocumentService;
use App\Domains\Billing\Services\SalesDocumentXmlBuilder;
use App\Domains\Billing\Support\FiscalCatalogs;
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
    ) {}

    public function index(): Response
    {
        return Inertia::render('Billing/Sales/Index', [
            'documents' => SalesDocument::with(['businessPartner:id,code,name', 'currency:id,code'])
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
        ]);
    }

    /**
     * Los seis paneles de la pantalla de facturación se alimentan de acá.
     * Los catálogos de la norma van como constantes (no son configuración);
     * los datos maestros sí salen de la base.
     */
    public function create(Request $request, HaciendaSigner $signer, HaciendaTransport $transport): Response
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
            ],
            'documentTypes' => DocumentType::where('origin_module', 'ventas')
                ->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'activities' => CompanyEconomicActivity::where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name', 'is_default']),
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
            'hacienda' => [
                'signer_configured' => $signer->isConfigured(),
                'transport_configured' => $transport->isConfigured(),
            ],
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
            'catalogs' => [
                'documentTypes' => FiscalCatalogs::DOCUMENT_TYPES,
                'saleConditions' => FiscalCatalogs::SALE_CONDITIONS,
                'paymentMethods' => FiscalCatalogs::PAYMENT_METHODS,
                'ivaRates' => FiscalCatalogs::IVA_RATES,
            ],
        ]);
    }

    /**
     * Descarga el XML del comprobante. Sin firmar: firmar exige el certificado
     * del contribuyente, y este archivo sirve para revisarlo o validarlo contra
     * el XSD oficial antes de emitir de verdad.
     */
    public function xml(int $salesDocument, CurrentCompany $currentCompany): StreamedResponse
    {
        $document = SalesDocument::findOrFail($salesDocument);
        $xml = $this->xmlBuilder->build($document, Company::findOrFail($currentCompany->id()));

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
        );

        try {
            $document = $this->postSalesDocumentService->post(
                Company::findOrFail($companyId), $input, $request->user()->id
            );
        } catch (\RuntimeException $e) {
            // Toda excepción de dominio ya revirtió comprobante, movimiento de
            // stock y asiento: acá solo se traduce a un mensaje de formulario.
            return back()->withErrors(['billing' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('sales-documents.show', $document->id)
            ->with('success', "Comprobante {$document->consecutive} emitido y registrado en el ERP.");
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
            'receiver_activity_code' => ['nullable', 'string', 'size:6'],
            'currency_id' => ['required', Rule::exists('currencies', 'id')],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'sale_condition' => ['required', Rule::in(array_keys(FiscalCatalogs::SALE_CONDITIONS))],
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

            'references' => ['array'],
            'references.*.document_type' => ['required', Rule::in(array_keys(FiscalCatalogs::REFERENCE_DOCUMENT_TYPES))],
            'references.*.number' => ['required', 'string', 'max:50'],
            'references.*.issued_at' => ['nullable', 'date'],
            'references.*.reason_code' => ['required', Rule::in(array_keys(FiscalCatalogs::REFERENCE_REASONS))],
            'references.*.reason' => ['required', 'string', 'min:3', 'max:180'],
        ]);
    }
}
