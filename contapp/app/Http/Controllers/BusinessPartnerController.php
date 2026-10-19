<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Billing\Support\CostaRicaTerritory;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Core\Support\CodeRules;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\PriceList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BusinessPartnerController extends Controller
{
    public function index(): Response
    {
        $partners = BusinessPartner::with(['category:id,code,name', 'costCenter:id,code,name'])
            ->orderBy('code')
            ->get([
                'id', 'code', 'name', 'type', 'tax_id', 'status', 'email', 'phone', 'contact_name', 'partner_since',
                'category_id', 'cost_center_id',
            ]);

        return Inertia::render('BusinessPartners/Index', [
            'partners' => $partners,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('BusinessPartners/Create', [
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es']),
            'currencies' => Currency::all(['id', 'code', 'symbol']),
            // Categorías/centros de costo: referencia informativa para
            // reportes, no listas restringidas por vigencia — mismo criterio
            // que "Tarifa de IVA vinculada" en ChartOfAccounts (no filtra por
            // vigencia acá, es solo configuración de a qué pertenece el socio).
            'categories' => BpCategory::orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => CostCenter::orderBy('code')->get(['id', 'code', 'name']),
            // Solo las activas: ofrecer una lista vencida o inactiva sería
            // asignarle al cliente un precio que nunca va a aplicar.
            'priceLists' => PriceList::where('status', 'active')->orderBy('code')
                ->get(['id', 'code', 'name', 'currency_id']),
            // Para la factura electrónica (CLAUDE.md secc. 38).
            'identificationTypes' => FiscalCatalogs::IDENTIFICATION_TYPES,
            'territory' => CostaRicaTerritory::tree(),
            'today' => now()->format('Y-m-d'),
            // El código que sigue en cada tipo (CodeRules): se propone, y se
            // puede cambiar solo al crear.
            'nextCodes' => collect(array_keys(BusinessPartner::FIRST_CODES))
                ->mapWithKeys(fn (string $type) => [$type => BusinessPartner::nextCode($type)])
                ->all(),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $this->validated($request, $companyId);

        // "Fecha de inicio como cliente o proveedor": si no se indica una
        // fecha distinta (ej. migrando un socio que ya era cliente antes de
        // usar CONTAPP), se asume que arranca hoy.
        $validated['partner_since'] ??= now()->format('Y-m-d');

        $partner = BusinessPartner::create([...$validated, 'status' => $validated['status'] ?? 'active']);

        // A sus partidas, si la persona puede verlas (Cobros y pagos es otro
        // permiso, CLAUDE.md secc. 36); si no, a la lista de socios.
        $canSeeOpenItems = app(ScreenAccessService::class)->allows($request->user(), $companyId, 'business_partners.open_items', 'read');

        return redirect()->route($canSeeOpenItems ? 'business-partners.open-items' : 'business-partners.index', $canSeeOpenItems ? $partner : [])
            ->with('success', "Socio {$partner->code} creado.");
    }

    public function edit(int $businessPartner): Response
    {
        $partner = BusinessPartner::findOrFail($businessPartner);

        return Inertia::render('BusinessPartners/Edit', [
            'partner' => $partner,
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es']),
            'currencies' => Currency::all(['id', 'code', 'symbol']),
            'categories' => BpCategory::orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => CostCenter::orderBy('code')->get(['id', 'code', 'name']),
            // Solo las activas: ofrecer una lista vencida o inactiva sería
            // asignarle al cliente un precio que nunca va a aplicar.
            'priceLists' => PriceList::where('status', 'active')->orderBy('code')
                ->get(['id', 'code', 'name', 'currency_id']),
            // Para la factura electrónica (CLAUDE.md secc. 38).
            'identificationTypes' => FiscalCatalogs::IDENTIFICATION_TYPES,
            'territory' => CostaRicaTerritory::tree(),
        ]);
    }

    public function update(Request $request, int $businessPartner, CurrentCompany $currentCompany): RedirectResponse
    {
        $partner = BusinessPartner::findOrFail($businessPartner);
        $validated = $this->validated($request, $currentCompany->id(), $partner->id);
        CodeRules::ensureUnchanged(BusinessPartner::CODE_LOCKED, $partner->code, $validated['code']);

        $partner->update($validated);

        return back()->with('success', "Socio {$partner->code} actualizado.");
    }

    private function validated(Request $request, ?int $companyId, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('business_partners')->where('company_id', $companyId)->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:client,supplier,both'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'economic_activity_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'partner_since' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer', Rule::exists('bp_categories', 'id')->where('company_id', $companyId)],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('company_id', $companyId)],
            'gl_account_id' => ['required', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('accepts_posting', true)],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            // Nullable = usa la lista predeterminada de la compañía, que es
            // lo correcto para la mayoría. Asignar una es la excepción:
            // mayorista, distribuidor, convenio.
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where('company_id', $companyId)],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
            // El receptor de una factura electrónica. La ubicación es
            // opcional en el XML, pero si va, va con los códigos de Hacienda
            // (provincia de 1 dígito, cantón y distrito de 2).
            'identification_type' => ['nullable', Rule::in(array_keys(FiscalCatalogs::IDENTIFICATION_TYPES))],
            // Cada código, de la tabla oficial y dentro del anterior.
            ...CostaRicaTerritory::rules($request->all(), required: false),
            'address_details' => ['nullable', 'string', 'max:250'],
        ], [
            'canton.regex' => 'El cantón es el código de dos dígitos (por ejemplo 01).',
            'district.regex' => 'El distrito es el código de dos dígitos (por ejemplo 03).',
        ]);

        // Hacienda rechaza una cédula que no tiene los dígitos de su tipo:
        // se dice acá, no al emitir la primera factura.
        if (filled($validated['identification_type'] ?? null) && filled($validated['tax_id'] ?? null)
            && ! FiscalCatalogs::identificationMatchesType($validated['identification_type'], $validated['tax_id'])) {
            throw ValidationException::withMessages([
                'tax_id' => 'La identificación no tiene los dígitos de una '.mb_strtolower(FiscalCatalogs::IDENTIFICATION_TYPES[$validated['identification_type']]).'.',
            ]);
        }

        return $validated;
    }
}
