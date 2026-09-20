<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\JournalEntryBulkImporter;
use App\Domains\Accounting\Services\JournalEntryExporter;
use App\Domains\Accounting\Services\JournalEntryListExporter;
use App\Domains\Accounting\Services\JournalEntryTemplateExporter;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\RetroactiveOpenItemService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->validateListFilters($request);

        $entries = $this->filteredEntriesQuery($filters)
            ->select(['id', 'document_type_id', 'document_number', 'number_series_id', 'series_number', 'posting_date', 'description', 'status'])
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('JournalEntries/Index', [
            'entries' => $entries,
            'filters' => $filters,
            'documentTypes' => DocumentType::generatesJournalActive()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Buscador de la pantalla de Registros: número de documento, tipo de
     * documento y rango de fechas de contabilización — tres filtros
     * independientes (no una búsqueda difusa combinada), reutilizados tanto
     * por index() como por listExport()/listExportPdf() para que la
     * exportación siempre traiga exactamente lo que se está viendo en
     * pantalla.
     */
    private function validateListFilters(Request $request): array
    {
        $validated = $request->validate([
            'document_number' => ['nullable', 'string', 'max:50'],
            'document_type_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            'document_number' => $validated['document_number'] ?? null,
            'document_type_id' => $validated['document_type_id'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    private function filteredEntriesQuery(array $filters): Builder
    {
        return JournalEntry::with(['documentType:id,code,name', 'numberSeries:id,name,holder_name'])
            ->when($filters['document_number'], fn ($q, $v) => $q->where('document_number', 'like', "%{$v}%"))
            ->when($filters['document_type_id'], fn ($q, $v) => $q->where('document_type_id', $v))
            ->when($filters['from'], fn ($q, $v) => $q->whereDate('posting_date', '>=', $v))
            ->when($filters['to'], fn ($q, $v) => $q->whereDate('posting_date', '<=', $v))
            ->latest('posting_date')
            ->latest('id');
    }

    /**
     * Botones "Exportar XLSX/PDF" de la pantalla de Registros — el
     * "Libro diario" del catálogo de reportes (CLAUDE.md secc. 5), con el
     * mismo encabezado de identidad de empresa que el resto de reportes.
     * Trae TODO lo que matchea el filtro (no solo la página actual) y el
     * total de débito/crédito de cada asiento, para poder verificar de un
     * vistazo que cada uno cuadra.
     */
    public function listExport(Request $request, CurrentCompany $currentCompany, JournalEntryListExporter $exporter, ReportHeaderFactory $headerFactory): StreamedResponse
    {
        $filters = $this->validateListFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $entries = $this->filteredEntriesQuery($filters)
            ->withSum('details as total_debit', 'debit_local')
            ->withSum('details as total_credit', 'credit_local')
            ->get();

        $header = $headerFactory->make($company, $request->user(), 'Registro de asientos', $this->listParamsSummary($filters));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $entries),
            'registro-de-asientos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function listExportPdf(Request $request, CurrentCompany $currentCompany, ReportHeaderFactory $headerFactory): \Illuminate\Http\Response
    {
        $filters = $this->validateListFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $entries = $this->filteredEntriesQuery($filters)
            ->withSum('details as total_debit', 'debit_local')
            ->withSum('details as total_credit', 'credit_local')
            ->get();

        $header = $headerFactory->make($company, $request->user(), 'Registro de asientos', $this->listParamsSummary($filters));

        return Pdf::loadView('journal-entries.list', compact('header', 'entries'))
            ->download('registro-de-asientos.pdf');
    }

    private function listParamsSummary(array $filters): string
    {
        $parts = [];

        if ($filters['document_number']) {
            $parts[] = "Documento: \"{$filters['document_number']}\"";
        }
        if ($filters['document_type_id']) {
            $type = DocumentType::find($filters['document_type_id']);
            $parts[] = 'Tipo: '.($type?->code ?? '—');
        }
        if ($filters['from'] || $filters['to']) {
            $parts[] = 'Del '.($filters['from'] ?? 'inicio').' al '.($filters['to'] ?? 'hoy');
        }

        return $parts ? implode(' — ', $parts) : 'Todos los asientos';
    }

    public function create(CurrentCompany $currentCompany): Response
    {
        return Inertia::render('JournalEntries/Create', [
            'entry' => null,
            ...$this->formProps($currentCompany->id()),
        ]);
    }

    public function edit(int $journalEntry, CurrentCompany $currentCompany): Response
    {
        $entry = JournalEntry::with('details')->findOrFail($journalEntry);

        abort_if($entry->status !== 'draft', 404);

        return Inertia::render('JournalEntries/Create', [
            'entry' => [
                'id' => $entry->id,
                'document_type_id' => $entry->document_type_id,
                'document_date' => $entry->document_date->format('Y-m-d'),
                'posting_date' => $entry->posting_date->format('Y-m-d'),
                'due_date' => $entry->due_date?->format('Y-m-d'),
                'description' => $entry->description,
                'number_series_id' => $entry->number_series_id,
                'lines' => $entry->details->map(fn ($d) => [
                    'mode' => $d->business_partner_id ? 'partner' : 'account',
                    'account_id' => $d->account_id,
                    'business_partner_id' => $d->business_partner_id,
                    'currency_id' => $d->currency_id,
                    'debit' => $d->debit_local,
                    'credit' => $d->credit_local,
                    'description' => $d->description,
                    'electronic_key' => $d->electronic_key,
                    'cost_allocation_rule_id' => $d->cost_allocation_rule_id,
                    'due_date' => $d->due_date?->format('Y-m-d'),
                    'reference_document' => $d->reference_document,
                    'reference_document_date' => $d->reference_document_date?->format('Y-m-d'),
                ]),
            ],
            // El destino de "anterior/siguiente" desde acá es SIEMPRE
            // journal-entries.show (ver Create.vue), nunca .edit: el vecino
            // cronológico puede ser un asiento ya contabilizado, y edit()
            // aborta con 404 para cualquier cosa que no sea un preliminar
            // (línea de arriba). Desde el detalle, "Editar" ya está
            // disponible si el vecino resulta ser otro preliminar.
            'nav' => $this->siblingEntryIds($entry),
            ...$this->formProps($currentCompany->id()),
        ]);
    }

    public function show(int $journalEntry): Response
    {
        $entry = JournalEntry::with([
            'documentType:id,code,name',
            'numberSeries:id,name,holder_name',
            'createdBy:id,name',
            'postedBy:id,name',
            'reversalOf:id,document_type_id,document_number',
            'reversalOf.documentType:id,code',
            'details' => fn ($q) => $q->orderBy('line_number'),
            'details.account:id,code,description_es',
            'details.businessPartner:id,code,name',
            'details.costCenter:id,code,name',
            'details.costAllocationRule:id,code,name',
            'details.currency:id,code,symbol',
            'details.tax.taxRate:id,code,name,percentage',
        ])->findOrFail($journalEntry);

        // El asiento que reversa a este (si existe) no es una relación
        // Eloquent propia — es la "vuelta" de reversalOf, se busca aparte.
        $reversedBy = JournalEntry::where('reversal_of_id', $entry->id)
            ->with('documentType:id,code')
            ->first(['id', 'document_type_id', 'document_number']);

        $firstDetail = $entry->details->first();

        // Líneas con socio que YA tienen una partida (abierta o parcial) —
        // para distinguir "corregir vencimiento de una partida existente" de
        // "esta línea nunca abrió partida" (ver updateLineDueDate(): un
        // asiento posteado sin marcar "abre partida" en su momento puede
        // dejar una cuenta por cobrar/pagar real sin nada que aplicarle un
        // pago después — acá se ofrece abrirla retroactivamente).
        $detailIdsWithOpenItem = BpOpenItem::where('status', '!=', 'closed')
            ->whereIn('origin_journal_detail_id', $entry->details->pluck('id'))
            ->pluck('origin_journal_detail_id')
            ->all();

        return Inertia::render('JournalEntries/Show', [
            'entry' => [
                'id' => $entry->id,
                'status' => $entry->status,
                'document_type' => $entry->documentType,
                'document_number' => $entry->document_number,
                'number_series' => $entry->numberSeries,
                'series_number' => $entry->series_number,
                'document_date' => $entry->document_date->format('Y-m-d'),
                'posting_date' => $entry->posting_date->format('Y-m-d'),
                'due_date' => $entry->due_date?->format('Y-m-d'),
                'description' => $entry->description,
                'created_by' => $entry->createdBy,
                'posted_by' => $entry->postedBy,
                'posted_at' => $entry->posted_at?->format('Y-m-d H:i'),
                'reversal_of' => $entry->reversalOf ? [
                    'id' => $entry->reversalOf->id,
                    'label' => "{$entry->reversalOf->documentType->code}-{$entry->reversalOf->document_number}",
                ] : null,
                'reversed_by' => $reversedBy ? [
                    'id' => $reversedBy->id,
                    'label' => "{$reversedBy->documentType->code}-{$reversedBy->document_number}",
                ] : null,
                // Uniforme entre líneas: se resuelve una sola vez por documento
                // (misma fecha, mismo override manual si lo hubo), así que basta
                // leerlo de la primera línea. Null en un borrador: saveDraft()
                // todavía no calcula conversión, eso se resuelve al contabilizar.
                'exchange_rate_lc_fc' => $firstDetail?->exchange_rate_lc_fc,
                'exchange_rate_fc_sc' => $firstDetail?->exchange_rate_fc_sc,
                'lines' => $entry->details->map(fn ($d) => [
                    'id' => $d->id,
                    'account' => $d->account,
                    'business_partner' => $d->businessPartner,
                    'cost_center' => $d->costCenter,
                    'cost_allocation_rule' => $d->costAllocationRule,
                    'currency' => $d->currency,
                    'description' => $d->description,
                    'debit_local' => $d->debit_local,
                    'credit_local' => $d->credit_local,
                    'debit_foreign' => $d->debit_foreign,
                    'credit_foreign' => $d->credit_foreign,
                    'debit_system' => $d->debit_system,
                    'credit_system' => $d->credit_system,
                    'electronic_key' => $d->electronic_key,
                    'due_date' => $d->due_date?->format('Y-m-d'),
                    'reference_document' => $d->reference_document,
                    'reference_document_date' => $d->reference_document_date?->format('Y-m-d'),
                    'has_open_item' => in_array($d->id, $detailIdsWithOpenItem, true),
                    'tax' => $d->tax ? [
                        'tax_rate' => $d->tax->taxRate,
                        'taxable_base' => $d->tax->taxable_base,
                        'tax_amount' => $d->tax->tax_amount,
                    ] : null,
                ]),
            ],
            'company' => $this->companyCurrencies(Company::with(['localCurrency:id,code', 'foreignCurrency:id,code', 'systemCurrency:id,code'])->findOrFail($entry->company_id)),
            // Para el botón "Exportar registro" (DocumentTypeRegisterPanel):
            // el mismo universo de tipos que ofrece Reportes > Registro por
            // tipo de documento, precargado acá para no ir hasta esa pantalla
            // solo para exportar el registro de este mismo tipo.
            'documentTypes' => DocumentType::generatesJournalActive()->orderBy('code')->get(['id', 'code', 'name']),
            // Para "vincular socio de negocio" en una línea que se
            // contabilizó sin uno (ver linkLineBusinessPartner()) — el
            // candidato válido para cada línea se filtra en el propio
            // Show.vue por gl_account_id === account de la línea.
            'businessPartners' => BusinessPartner::where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'gl_account_id']),
            'nav' => $this->siblingEntryIds($entry),
        ]);
    }

    /**
     * Botón "Exportar" del DocumentToolbar en Show.vue — XLSX de un solo
     * asiento, misma fuente de datos que la pantalla (ver JournalEntryExporter).
     */
    public function export(Request $request, int $journalEntry, JournalEntryExporter $exporter, ReportHeaderFactory $headerFactory): StreamedResponse
    {
        $entry = $this->findEntryForPresentation($journalEntry);
        $header = $this->presentationHeader($entry, $request, $headerFactory);

        $filename = $entry->document_number
            ? "{$entry->documentType->code}-{$entry->document_number}.xlsx"
            : "asiento-preliminar-{$entry->id}.xlsx";

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $entry),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    /**
     * PDF real generado en el servidor (dompdf), mismo patrón que Balance
     * General/Estado de Resultados/etc. (ver BalanceSheetController) — no es
     * "imprimir la pantalla", es un documento aparte con el mismo encabezado
     * de identidad de empresa (logo, cédula jurídica, dirección) que
     * cualquier otro reporte exportado.
     */
    public function exportPdf(Request $request, int $journalEntry, ReportHeaderFactory $headerFactory): \Illuminate\Http\Response
    {
        $entry = $this->findEntryForPresentation($journalEntry);
        $header = $this->presentationHeader($entry, $request, $headerFactory);

        $filename = $entry->document_number
            ? "{$entry->documentType->code}-{$entry->document_number}.pdf"
            : "asiento-preliminar-{$entry->id}.pdf";

        return Pdf::loadView('journal-entries.presentation', compact('header', 'entry'))
            ->download($filename);
    }

    /**
     * Pantalla de "Presentar documento": botón nuevo en Show.vue/Create.vue
     * que abre, en pestaña aparte, una vista limpia (sin menú lateral ni
     * barra superior — ver AppLayout) del asiento formateado como un
     * comprobante, con el mismo encabezado de identidad de empresa que el
     * PDF/XLSX y botones para generarlos o imprimir directo desde ahí.
     */
    public function presentation(Request $request, int $journalEntry, ReportHeaderFactory $headerFactory): Response
    {
        $entry = $this->findEntryForPresentation($journalEntry);
        $header = $this->presentationHeader($entry, $request, $headerFactory);
        $company = Company::findOrFail($entry->company_id);

        return Inertia::render('JournalEntries/Presentation', [
            'entry' => [
                'id' => $entry->id,
                'status' => $entry->status,
                'label' => $header->title,
                'description' => $entry->description,
                'posting_date' => $entry->posting_date->format('Y-m-d'),
                'document_date' => $entry->document_date->format('Y-m-d'),
                'lines' => $entry->details->map(fn (JournalDetail $d) => [
                    'owner' => $d->businessPartner
                        ? "{$d->businessPartner->code} — {$d->businessPartner->name}"
                        : "{$d->account?->code} — {$d->account?->description_es}",
                    'cost_center' => $d->costCenter ? "{$d->costCenter->code} — {$d->costCenter->name}" : null,
                    'currency_code' => $d->currency?->code,
                    'debit' => $d->debit_local,
                    'credit' => $d->credit_local,
                    'description' => $d->description,
                ]),
            ],
            'header' => [
                'company_name' => $header->companyName,
                'tax_id' => $header->taxId,
                'address' => $header->address,
                'logo_url' => $this->publicLogoUrl($company->logo_path),
                'generated_by_name' => $header->generatedByName,
                'generated_at' => $header->generatedAt->format('Y-m-d H:i'),
            ],
        ]);
    }

    private function findEntryForPresentation(int $journalEntry): JournalEntry
    {
        return JournalEntry::with([
            'documentType:id,code',
            'details.account:id,code,description_es',
            'details.businessPartner:id,code,name',
            'details.costCenter:id,code,name',
            'details.currency:id,code',
        ])->findOrFail($journalEntry);
    }

    private function presentationHeader(JournalEntry $entry, Request $request, ReportHeaderFactory $headerFactory): ReportHeader
    {
        $company = Company::findOrFail($entry->company_id);

        $label = $entry->document_number
            ? "{$entry->documentType->code}-{$entry->document_number}"
            : "{$entry->documentType->code} — preliminar";

        return $headerFactory->make(
            $company,
            $request->user(),
            $label,
            "Fecha de contabilización: {$entry->posting_date->format('Y-m-d')}"
        );
    }

    private function publicLogoUrl(?string $logoPath): ?string
    {
        if ($logoPath === null) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($logoPath) ? $disk->url($logoPath) : null;
    }

    /**
     * Orden natural de navegación entre documentos: fecha de
     * contabilización y luego id, ascendente — "siguiente" avanza hacia lo
     * más reciente, "anterior" retrocede hacia lo más viejo (igual criterio
     * que un libro diario cronológico). No es el mismo orden que
     * index() (que lista lo más nuevo primero); acá lo que importa es poder
     * hojear el diario en su secuencia real, no la lista.
     *
     * @return array{prev: int|null, next: int|null, first: int|null, last: int|null}
     */
    private function siblingEntryIds(JournalEntry $entry): array
    {
        $postingDate = $entry->posting_date->format('Y-m-d');

        // whereDate() (no where() con un string 'Y-m-d' plano) porque
        // posting_date es un DATETIME con hora en cero: comparar el string
        // corto contra la columna completa falla en algunos motores (ej.
        // SQLite compara como texto, y "2026-09-10" != "2026-09-10 00:00:00")
        // — whereDate normaliza ambos lados a solo fecha en cualquier driver.
        $prev = JournalEntry::where(function ($q) use ($entry, $postingDate) {
            $q->whereDate('posting_date', '<', $postingDate)
                ->orWhere(function ($q2) use ($entry, $postingDate) {
                    $q2->whereDate('posting_date', $postingDate)->where('id', '<', $entry->id);
                });
        })->orderByDesc('posting_date')->orderByDesc('id')->value('id');

        $next = JournalEntry::where(function ($q) use ($entry, $postingDate) {
            $q->whereDate('posting_date', '>', $postingDate)
                ->orWhere(function ($q2) use ($entry, $postingDate) {
                    $q2->whereDate('posting_date', $postingDate)->where('id', '>', $entry->id);
                });
        })->orderBy('posting_date')->orderBy('id')->value('id');

        return [
            'prev' => $prev,
            'next' => $next,
            'first' => JournalEntry::orderBy('posting_date')->orderBy('id')->value('id'),
            'last' => JournalEntry::orderByDesc('posting_date')->orderByDesc('id')->value('id'),
        ];
    }

    /**
     * Búsqueda liviana (JSON, no una pantalla propia) reutilizada por dos
     * pantallas: "buscar" del DocumentToolbar en Show.vue (saltar a otro
     * documento) y el selector "cargar desde un documento existente" en
     * Create.vue (precargarlo como plantilla vía duplicate(), sin tocar el
     * original). Busca por código de tipo, número de documento o
     * descripción — no exige coincidencia exacta.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $entries = JournalEntry::with('documentType:id,code')
            ->where(function ($query) use ($q) {
                $query->where('description', 'like', "%{$q}%")
                    ->orWhere('document_number', 'like', "%{$q}%")
                    ->orWhereHas('documentType', fn ($dt) => $dt->where('code', 'like', "%{$q}%"));
            })
            ->latest('posting_date')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'document_type_id', 'document_number', 'posting_date', 'description', 'status']);

        return response()->json($entries->map(fn (JournalEntry $e) => [
            'id' => $e->id,
            'label' => $e->document_number ? "{$e->documentType->code}-{$e->document_number}" : "{$e->documentType->code} — preliminar",
            'description' => $e->description,
            'posting_date' => $e->posting_date->format('Y-m-d'),
            'status' => $e->status,
        ])->values());
    }

    /**
     * Única excepción explícita a la inmutabilidad del asiento contabilizado
     * (CLAUDE.md: "un asiento contabilizado nunca se altera"): el vencimiento
     * de una línea no participa de la partida doble ni de ningún cálculo
     * contable — es puro dato de gestión de cartera — pero un error de tipeo
     * ahí corrompe para siempre la cédula de antigüedad de saldos y el
     * análisis de vencimientos si nunca se puede corregir. Nada más que este
     * campo se puede tocar por esta vía; cuenta, montos, socio, etc. siguen
     * intocables (solo se corrigen anulando y recontabilizando).
     *
     * Si la línea abrió una partida (bp_open_items.origin_journal_detail_id),
     * su vencimiento se actualiza también ahí — es la fuente que de verdad
     * leen AgingService/CashFlowProjectionService — para que esta pantalla y
     * esos reportes nunca muestren fechas distintas para lo mismo. Una
     * partida ya cerrada no se toca: ya no participa de esos reportes.
     *
     * Caso real que motivó esto: una línea con socio de negocio se
     * contabilizó SIN marcar "abre partida" (el checkbox del formulario, ver
     * JournalEntries/Create.vue) — el monto por cobrar/pagar es real, pero
     * nunca quedó nada en bp_open_items para poder "aplicar a partida" desde
     * un cobro/pago posterior. En vez de exigir anular y recontabilizar todo
     * (el asiento en sí sigue siendo correcto), si la línea tiene socio y
     * todavía no tiene ninguna partida no cerrada, definir acá un vencimiento
     * abre la partida retroactivamente por el monto ya contabilizado en esa
     * línea — el asiento original sigue sin tocarse.
     */
    public function updateLineDueDate(Request $request, int $journalEntry, int $line, RetroactiveOpenItemService $opener): RedirectResponse
    {
        $entry = JournalEntry::findOrFail($journalEntry);

        abort_unless($entry->status === 'posted', 404);

        $detail = JournalDetail::where('journal_entry_id', $entry->id)->findOrFail($line);

        $validated = $request->validate([
            'due_date' => ['nullable', 'date'],
        ]);

        $detail->update(['due_date' => $validated['due_date']]);

        $updatedExisting = BpOpenItem::where('origin_journal_detail_id', $detail->id)
            ->where('status', '!=', 'closed')
            ->update(['due_date' => $validated['due_date']]);

        if ($updatedExisting === 0 && $validated['due_date'] !== null && $detail->business_partner_id !== null) {
            $opener->open($detail, $entry->documentType->code, $entry->document_number, $validated['due_date']);

            return back()->with('success', 'Partida abierta retroactivamente con este vencimiento.');
        }

        return back()->with('success', 'Vencimiento actualizado.');
    }

    /**
     * Segunda excepción explícita, tan acotada como la del vencimiento: una
     * línea que se contabilizó apuntando a la cuenta de control de un socio
     * de negocio que TODAVÍA no existía como registro (caso real: se cargó
     * un saldo inicial antes de terminar de dar de alta a los socios) queda
     * con business_partner_id en null para siempre, aunque la cuenta ya sea
     * inequívocamente la de ese socio. Nunca se puede reemplazar un socio ya
     * vinculado (eso sí sería cambiar de qué trata la línea) — solo llenar
     * el hueco cuando hoy está en null.
     *
     * Guarda de seguridad, no solo un checkbox de confianza: el socio elegido
     * tiene que tener esa MISMA cuenta como su cuenta de control
     * (gl_account_id). Así la vinculación nunca puede "mover" el monto a una
     * cuenta distinta a la que el asiento ya tiene contabilizada — solo
     * puede confirmar a cuál de los socios que usan esa cuenta pertenece.
     *
     * Si la línea ya tenía vencimiento definido y todavía no tiene partida,
     * vincular el socio abre la partida en el mismo paso (mismo criterio que
     * updateLineDueDate) — así ya queda lista para "aplicar a partida" desde
     * un cobro/pago sin un segundo paso.
     */
    public function linkLineBusinessPartner(Request $request, int $journalEntry, int $line, CurrentCompany $currentCompany, RetroactiveOpenItemService $opener): RedirectResponse
    {
        $entry = JournalEntry::findOrFail($journalEntry);

        abort_unless($entry->status === 'posted', 404);

        $detail = JournalDetail::where('journal_entry_id', $entry->id)->findOrFail($line);

        if ($detail->business_partner_id !== null) {
            return back()->withErrors(['business_partner_id' => 'Esta línea ya tiene un socio de negocio vinculado; no se puede reemplazar por esta vía.']);
        }

        $validated = $request->validate([
            'business_partner_id' => ['required', 'integer', Rule::exists('business_partners', 'id')->where('company_id', $currentCompany->id())],
        ]);

        $partner = BusinessPartner::findOrFail($validated['business_partner_id']);

        if ($partner->gl_account_id !== $detail->account_id) {
            return back()->withErrors([
                'business_partner_id' => "El socio {$partner->code} no tiene esta cuenta como su cuenta de control — solo se puede vincular un socio cuya cuenta de control sea exactamente la que ya tiene esta línea, para no cambiar de qué cuenta se trata.",
            ]);
        }

        $detail->update(['business_partner_id' => $partner->id]);

        if ($detail->due_date !== null) {
            $hasOpenItem = BpOpenItem::where('origin_journal_detail_id', $detail->id)->where('status', '!=', 'closed')->exists();

            if (! $hasOpenItem) {
                $opener->open($detail, $entry->documentType->code, $entry->document_number, $detail->due_date->format('Y-m-d'));
            }
        }

        return back()->with('success', 'Socio de negocio vinculado.');
    }

    public function reverse(Request $request, int $journalEntry, CurrentCompany $currentCompany, PostJournalService $service): RedirectResponse
    {
        $original = JournalEntry::findOrFail($journalEntry);
        $company = Company::findOrFail($currentCompany->id());

        try {
            $reversal = $service->reverse($company, $original, now(), null, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return redirect()->route('journal-entries.show', $reversal->id)
            ->with('success', "Asiento {$original->documentType->code}-{$original->document_number} anulado. Se contabilizó el asiento de reversión.");
    }

    /**
     * Precarga el formulario de creación con las líneas de otro asiento ya
     * existente — el usuario las ajusta y "Contabilizar"/"Guardar como
     * preliminar" crea un documento NUEVO, sin tocar el original. Misma
     * pantalla que create()/edit() (Create.vue ya distingue "hay entry.id" =
     * editando un borrador, vs. sin id = solo precarga).
     */
    public function duplicate(int $journalEntry, CurrentCompany $currentCompany): Response
    {
        $entry = JournalEntry::with('details')->findOrFail($journalEntry);

        return Inertia::render('JournalEntries/Create', [
            'entry' => [
                'id' => null,
                'document_type_id' => $entry->document_type_id,
                'document_date' => now()->format('Y-m-d'),
                'posting_date' => now()->format('Y-m-d'),
                'due_date' => null,
                'description' => $entry->description,
                'number_series_id' => null,
                'lines' => $this->collapseAllocatedLinesForDuplicate($entry->details),
            ],
            ...$this->formProps($currentCompany->id()),
        ]);
    }

    /**
     * Un asiento ya contabilizado con líneas de norma de reparto trae varias
     * filas reales por línea lógica (una por centro de costo, ver
     * PostJournalService::post()) — duplicar tiene que RECOLAPSARLAS de
     * vuelta a una sola línea por norma antes de precargar el formulario. Si
     * no, al volver a contabilizar el duplicado cada una de esas N filas se
     * repartiría de nuevo por la misma norma (un reparto del reparto),
     * multiplicando el monto en vez de reproducirlo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collapseAllocatedLinesForDuplicate(Collection $details): Collection
    {
        return $details
            ->groupBy(fn ($d) => $d->cost_allocation_rule_id
                ? "rule:{$d->cost_allocation_rule_id}:{$d->account_id}:{$d->business_partner_id}"
                : "single:{$d->id}")
            ->map(function ($rows) {
                $first = $rows->first();

                return [
                    'mode' => $first->business_partner_id ? 'partner' : 'account',
                    'account_id' => $first->account_id,
                    'business_partner_id' => $first->business_partner_id,
                    'currency_id' => $first->currency_id,
                    'debit' => $rows->reduce(fn ($carry, $r) => bcadd($carry, (string) $r->debit_local, 2), '0.00'),
                    'credit' => $rows->reduce(fn ($carry, $r) => bcadd($carry, (string) $r->credit_local, 2), '0.00'),
                    'description' => $first->description,
                    // La clave electrónica, el vencimiento y el documento de
                    // referencia (número y fecha) son del documento original —
                    // un duplicado es un documento distinto, así que arranca
                    // sin ellos para que el usuario los cargue de nuevo si de
                    // verdad corresponden.
                    'electronic_key' => null,
                    'cost_allocation_rule_id' => $first->cost_allocation_rule_id,
                    'due_date' => null,
                    'reference_document' => null,
                    'reference_document_date' => null,
                ];
            })
            ->values();
    }

    public function store(Request $request, CurrentCompany $currentCompany, PostJournalService $service): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $this->validated($request, $companyId);

        $company = Company::findOrFail($companyId);
        $documentType = DocumentType::findOrFail($validated['document_type_id']);
        $lines = $this->linesFromRequest($validated, $validated['intent'] === 'draft');

        try {
            if ($validated['intent'] === 'draft') {
                [$lines, $balancedNote] = $this->balanceLinesForDraft($lines, $company);

                $entry = $service->saveDraft(
                    $company,
                    $documentType,
                    new \DateTime($validated['document_date']),
                    new \DateTime($validated['posting_date']),
                    $lines,
                    $validated['description'] ?? null,
                    $request->user()->id,
                    dueDate: isset($validated['due_date']) ? new \DateTime($validated['due_date']) : null,
                );

                return redirect()->route('journal-entries.index')
                    ->with('success', "Documento {$documentType->code} guardado como preliminar.{$balancedNote}");
            }

            $entry = $service->post(
                $company,
                $documentType,
                new \DateTime($validated['document_date']),
                new \DateTime($validated['posting_date']),
                $lines,
                $validated['description'] ?? null,
                $request->user()->id,
                isset($validated['number_series_id']) ? (int) $validated['number_series_id'] : null,
                manualExchangeRate: $validated['exchange_rate'] ?? null,
                dueDate: isset($validated['due_date']) ? new \DateTime($validated['due_date']) : null,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('journal-entries.index')
            ->with('success', "Documento {$documentType->code}-{$entry->document_number} contabilizado.");
    }

    public function update(Request $request, int $journalEntry, CurrentCompany $currentCompany, PostJournalService $service): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $draft = JournalEntry::findOrFail($journalEntry);
        abort_if($draft->status !== 'draft', 404);

        $validated = $this->validated($request, $companyId, $draft->id);

        $company = Company::findOrFail($companyId);
        $documentType = DocumentType::findOrFail($validated['document_type_id']);
        $lines = $this->linesFromRequest($validated, $validated['intent'] === 'draft');

        try {
            if ($validated['intent'] === 'draft') {
                [$lines, $balancedNote] = $this->balanceLinesForDraft($lines, $company);

                $service->saveDraft(
                    $company,
                    $documentType,
                    new \DateTime($validated['document_date']),
                    new \DateTime($validated['posting_date']),
                    $lines,
                    $validated['description'] ?? null,
                    $request->user()->id,
                    $draft,
                    dueDate: isset($validated['due_date']) ? new \DateTime($validated['due_date']) : null,
                );

                return redirect()->route('journal-entries.index')
                    ->with('success', "Documento {$documentType->code} actualizado como preliminar.{$balancedNote}");
            }

            $entry = $service->post(
                $company,
                $documentType,
                new \DateTime($validated['document_date']),
                new \DateTime($validated['posting_date']),
                $lines,
                $validated['description'] ?? null,
                $request->user()->id,
                isset($validated['number_series_id']) ? (int) $validated['number_series_id'] : null,
                $draft,
                manualExchangeRate: $validated['exchange_rate'] ?? null,
                dueDate: isset($validated['due_date']) ? new \DateTime($validated['due_date']) : null,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('journal-entries.index')
            ->with('success', "Documento {$documentType->code}-{$entry->document_number} contabilizado.");
    }

    public function destroy(int $journalEntry): RedirectResponse
    {
        $entry = JournalEntry::findOrFail($journalEntry);

        // Regla innegociable de CLAUDE.md: delete físico SOLO de borradores.
        // Un asiento contabilizado nunca se borra, ni siquiera acá.
        abort_if($entry->status !== 'draft', 404);

        $entry->details()->delete();
        $entry->delete();

        return redirect()->route('journal-entries.index')->with('success', 'Borrador eliminado.');
    }

    public function template(CurrentCompany $currentCompany, JournalEntryTemplateExporter $exporter): StreamedResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $company),
            'asiento.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function import(Request $request, CurrentCompany $currentCompany, JournalEntryBulkImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx'],
        ]);

        $company = Company::findOrFail($currentCompany->id());

        $result = $importer->import($request->file('file')->getRealPath(), $company, $request->user()->id);

        if ($result->hasErrors()) {
            return back()->with('importErrors', $result->errors);
        }

        return redirect()->route('journal-entries.edit', $result->journalEntryId)
            ->with('success', 'Asiento importado como preliminar. Revisalo y contabilizalo cuando esté listo.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(int $companyId): array
    {
        $company = Company::with(['localCurrency:id,code', 'foreignCurrency:id,code', 'systemCurrency:id,code'])
            ->findOrFail($companyId);

        return [
            'company' => $this->companyCurrencies($company),
            // Historial reciente de TC local/extranjera: el formulario lo usa
            // para mostrar (y dejar anular) el tipo de cambio que se aplicaría
            // automáticamente según la fecha del asiento — mismo criterio que
            // ExchangeRateController::index(), sin repetir la consulta ahí.
            'exchangeRates' => $company->foreign_currency_id
                ? ExchangeRate::where('company_id', $company->id)
                    ->where('currency_id', $company->foreign_currency_id)
                    ->orderByDesc('rate_date')
                    ->limit(90)
                    ->get(['rate_date', 'rate'])
                : [],
            'documentTypes' => DocumentType::where('status', 'active')
                ->where('generates_journal', true)
                // Los tipos reservados para saldos iniciales, traspasos de
                // reconciliación y el cierre anual (ver OpeningBalanceBulkImporter,
                // AccountReconciliationController, PeriodCloseService::closeYear())
                // quedan fuera del formulario manual a propósito: "preconcebido
                // solo para ese fin" (docs/decisiones.md 2026-08-24) — se
                // contabilizan únicamente desde sus pantallas dedicadas.
                ->where('is_opening_type', false)
                ->where('is_reconciliation_type', false)
                ->where('is_closing_type', false)
                ->with(['numberSeries' => fn ($q) => $q->where('is_active', true)->whereColumn('next_number', '<=', 'range_to')])
                ->get(['id', 'code', 'name', 'currency_mode', 'requires_electronic_key', 'bp_line_requirement'])
                ->map(fn (DocumentType $dt) => [
                    'id' => $dt->id,
                    'code' => $dt->code,
                    'name' => $dt->name,
                    'currency_mode' => $dt->currency_mode,
                    'requires_electronic_key' => $dt->requires_electronic_key,
                    'bp_line_requirement' => $dt->bp_line_requirement,
                    'number_series' => $dt->numberSeries->map(fn ($s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'holder_name' => $s->holder_name,
                        'next_number' => $s->next_number,
                        'range_to' => $s->range_to,
                    ]),
                ]),
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->with('taxRate:id,code,name,percentage,effective_from,effective_to')
                ->orderBy('code')
                ->get(['id', 'code', 'description_es', 'requires_cost_center', 'tax_classification', 'tax_rate_id']),
            'currencies' => Currency::all(['id', 'code', 'symbol']),
            'businessPartners' => BusinessPartner::where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'gl_account_id', 'payment_terms_days']),
            // Partidas abiertas (no cerradas) de la compañía activa, para el
            // selector "aplicar a partida" de una línea con socio (ver
            // DocumentType::BP_LINE_REQUIREMENTS). BpOpenItem no tiene
            // company_id propio (se filtra vía business_partner_id ->
            // business_partners.company_id, mismo patrón de PostJournalService)
            // — whereHas('businessPartner') sin closure ya alcanza porque
            // BusinessPartner trae su propio CompanyScope automático.
            'openItems' => BpOpenItem::whereHas('businessPartner')
                ->where('status', '!=', 'closed')
                ->with('currency:id,code')
                ->orderByDesc('due_date')
                ->get(['id', 'business_partner_id', 'document_type_code', 'document_number', 'due_date', 'balance', 'currency_id']),
            // Vigentes hoy: mismo filtro que ya regía para el centro de
            // costo directo, aplicado ahora a la norma de reparto (ver
            // CostAllocationRule::isEffectiveOn(), PostJournalService::post()).
            'costAllocationRules' => CostAllocationRule::where('is_active', true)
                ->whereDate('valid_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()))
                ->with('lines.costCenter:id,code,name')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ];
    }

    private function validated(Request $request, ?int $companyId, ?int $ignoreEntryId = null): array
    {
        // intent es opcional para quien llama: sin indicarlo, se asume 'post'
        // (el comportamiento de siempre, antes de que existiera el borrador).
        $request->merge(['intent' => $request->input('intent', 'post')]);
        $intent = $request->input('intent');

        // La clave electrónica solo es obligatoria al contabilizar en serio
        // (post), nunca en un borrador — mismo criterio de tolerancia que ya
        // aplica al cuadre de la partida doble y a las demás exigencias de
        // PostJournalService::post() vs. saveDraft().
        $requiresElectronicKey = $intent === 'post' && DocumentType::where('id', $request->input('document_type_id'))
            ->where('company_id', $companyId)
            ->value('requires_electronic_key');

        return $request->validate([
            'intent' => ['required', 'in:draft,post'],
            'document_type_id' => ['required', 'integer'],
            // document_date es puramente informativa (fecha del documento
            // fuente); posting_date es la RECTORA (fija período fiscal, TC y
            // vigencias, ver PostJournalService::post()) — las dos son
            // siempre obligatorias, igual en borrador que al contabilizar,
            // mismo criterio que ya regía para document_date sola antes de
            // que existiera esta separación (docs/decisiones.md 2026-08-22).
            'document_date' => ['required', 'date'],
            'posting_date' => ['required', 'date'],
            // Vencimiento a nivel de encabezado: opcional, precarga cada
            // línea nueva en el formulario pero no obliga a nada acá.
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'number_series_id' => ['nullable', 'integer', Rule::exists('document_type_number_series', 'id')->where('company_id', $companyId)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            // Un mismo asiento puede juntar varias facturas de compra a la
            // vez (una línea de gasto + su IVA por cada una, contra una sola
            // cuenta de pago) — la clave es por LÍNEA, no por asiento. Acá
            // solo se valida contra duplicados ya contabilizados en otros
            // asientos de la compañía; que ninguna línea la repita dentro
            // del mismo envío, y que al menos una la traiga cuando el tipo
            // de documento la exige, se revisa aparte sobre el arreglo
            // completo (regla de 'lines' más abajo) porque son chequeos que
            // necesitan ver todas las líneas juntas, no una por una.
            'lines' => [
                'required', 'array', $intent === 'draft' ? 'min:1' : 'min:2',
                function ($attribute, $value, $fail) use ($requiresElectronicKey) {
                    $keys = collect($value)->pluck('electronic_key')->filter();

                    if ($keys->count() !== $keys->unique()->count()) {
                        $fail('Dos líneas del mismo asiento no pueden tener la misma clave numérica electrónica.');
                    }

                    if ($requiresElectronicKey && $keys->isEmpty()) {
                        $fail('Este tipo de documento exige la clave numérica electrónica en al menos una línea.');
                    }
                },
            ],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.currency_id' => ['required', 'integer'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.electronic_key' => [
                'nullable',
                'digits:50',
                function ($attribute, $value, $fail) use ($companyId, $ignoreEntryId) {
                    $exists = JournalDetail::whereHas('journalEntry', function ($q) use ($companyId, $ignoreEntryId) {
                        $q->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId);

                        if ($ignoreEntryId) {
                            $q->where('id', '!=', $ignoreEntryId);
                        }
                    })->where('electronic_key', $value)->exists();

                    if ($exists) {
                        $fail('Esta clave numérica ya está registrada en otra línea de otro asiento de esta compañía.');
                    }
                },
            ],
            'lines.*.cost_allocation_rule_id' => ['nullable', 'integer', Rule::exists('cost_allocation_rules', 'id')->where('company_id', $companyId)],
            'lines.*.business_partner_id' => ['nullable', 'integer', Rule::exists('business_partners', 'id')->where('company_id', $companyId)],
            'lines.*.tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'lines.*.taxable_base' => ['nullable', 'numeric', 'min:0', 'required_with:lines.*.tax_rate_id'],
            'lines.*.due_date' => ['nullable', 'date'],
            // Documento fuente de ESTA línea (ej. la factura #4521 de un
            // proveedor) y su fecha — independientes entre sí y de la fecha
            // de contabilización del asiento: un asiento puede juntar varias
            // facturas con números y fechas distintos (mismo motivo por el
            // que electronic_key es por línea, ver comentario más arriba).
            'lines.*.reference_document' => ['nullable', 'string', 'max:255'],
            'lines.*.reference_document_date' => ['nullable', 'date'],
            'lines.*.opens_item' => ['boolean'],
            // Existencia nada más: que la partida sea del mismo socio/compañía
            // que esta línea lo valida PostJournalService::applyToExistingOpenItem()
            // dentro de la transacción (mismo criterio que ya se usa para
            // tax_rate_id, cuya vigencia/monto también se valida más adentro).
            'lines.*.apply_to_open_item_id' => ['nullable', 'integer', 'exists:bp_open_items,id'],
        ]);
    }

    /**
     * @return JournalLineInput[]
     */
    private function linesFromRequest(array $validated, bool $isDraft): array
    {
        return array_map(
            fn (array $line) => new JournalLineInput(
                accountId: (int) $line['account_id'],
                currencyId: (int) $line['currency_id'],
                debit: $line['debit'],
                credit: $line['credit'],
                description: $line['description'] ?? null,
                businessPartnerId: isset($line['business_partner_id']) ? (int) $line['business_partner_id'] : null,
                costAllocationRuleId: isset($line['cost_allocation_rule_id']) ? (int) $line['cost_allocation_rule_id'] : null,
                taxRateId: isset($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
                taxableBase: $line['taxable_base'] ?? null,
                allowZeroAmount: $isDraft,
                electronicKey: $line['electronic_key'] ?? null,
                dueDate: $line['due_date'] ?? null,
                opensItem: $line['opens_item'] ?? false,
                applyToOpenItemId: isset($line['apply_to_open_item_id']) ? (int) $line['apply_to_open_item_id'] : null,
                referenceDocument: $line['reference_document'] ?? null,
                referenceDocumentDate: $line['reference_document_date'] ?? null,
            ),
            $validated['lines']
        );
    }

    /**
     * Un preliminar SÍ se puede guardar sin cuadrar (a diferencia de
     * contabilizar en serio, que sigue exigiendo partida doble exacta) —
     * pero dejarlo así, sin ninguna pista de cuánto falta ni dónde quedó,
     * es fácil de perder de vista entre muchos borradores. En vez de eso,
     * la diferencia se balancea sola contra una cuenta puente fija
     * ("9-00-00-00-000", igual segmentación que el resto del catálogo:
     * 1-2-2-2-3), creada de oficio la primera vez que hace falta — mismo
     * criterio que ya usan los tipos de documento reservados (ARR, saldos
     * iniciales). Al revisar el preliminar antes de contabilizarlo en
     * serio, esa línea de diferencia queda bien visible para corregir el
     * error real en vez de dejarlo escondido.
     *
     * @param  JournalLineInput[]  $lines
     * @return array{0: JournalLineInput[], 1: string}
     */
    private function balanceLinesForDraft(array $lines, Company $company): array
    {
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($lines as $line) {
            /** @var JournalLineInput $line */
            $totalDebit = bcadd($totalDebit, $line->debit, 2);
            $totalCredit = bcadd($totalCredit, $line->credit, 2);
        }

        $diff = bcsub($totalDebit, $totalCredit, 2);

        if (bccomp($diff, '0.00', 2) === 0) {
            return [$lines, ''];
        }

        $suspenseAccount = $this->suspenseAccount($company);
        $isDebitDeficit = bccomp($diff, '0.00', 2) < 0;
        $balancingAmount = $isDebitDeficit ? bcmul($diff, '-1', 2) : $diff;

        $lines[] = new JournalLineInput(
            accountId: $suspenseAccount->id,
            currencyId: $company->local_currency_id,
            debit: $isDebitDeficit ? $balancingAmount : 0,
            credit: $isDebitDeficit ? 0 : $balancingAmount,
            description: 'Diferencia de cuadre — preliminar',
            allowZeroAmount: true,
        );

        return [$lines, " Diferencia de {$balancingAmount} balanceada contra la cuenta puente {$suspenseAccount->code} — revisala antes de contabilizar."];
    }

    private function suspenseAccount(Company $company): ChartOfAccount
    {
        return ChartOfAccount::firstOrCreate(
            ['company_id' => $company->id, 'code' => '9-00-00-00-000'],
            [
                'description_es' => 'Cuenta puente — diferencias de cuadre en preliminares',
                'description_en' => 'Suspense account — draft imbalance',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'currency_mode' => 'local',
                'accepts_posting' => true,
                'requires_business_partner' => false,
                'is_cash_account' => false,
                'requires_cost_center' => false,
                'is_financial_report' => true,
                'level' => 1,
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function companyCurrencies(Company $company): array
    {
        return [
            'local_currency' => $company->localCurrency ? ['id' => $company->localCurrency->id, 'code' => $company->localCurrency->code] : null,
            'foreign_currency' => $company->foreignCurrency ? ['id' => $company->foreignCurrency->id, 'code' => $company->foreignCurrency->code] : null,
            'system_currency' => $company->systemCurrency ? ['id' => $company->systemCurrency->id, 'code' => $company->systemCurrency->code] : null,
        ];
    }
}
