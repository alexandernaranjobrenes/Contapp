<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\Currency;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'today' => now()->format('Y-m-d'),
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

        return redirect()->route('business-partners.open-items', $partner)
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
        ]);
    }

    public function update(Request $request, int $businessPartner, CurrentCompany $currentCompany): RedirectResponse
    {
        $partner = BusinessPartner::findOrFail($businessPartner);
        $validated = $this->validated($request, $currentCompany->id(), $partner->id);

        $partner->update($validated);

        return back()->with('success', "Socio {$partner->code} actualizado.");
    }

    private function validated(Request $request, ?int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
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
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
    }
}
