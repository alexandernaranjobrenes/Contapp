<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Tax\Models\JournalDetailTax;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IVA es catálogo nacional (App\Domains\Tax\Models\TaxType::class docblock:
 * "el IVA es ley nacional, no varía por compañía"), company_id NULL — así
 * que escribir sobre una fila global afecta a TODAS las compañías del
 * tenant a la vez. Desde 2026-09-07 conviven con eso los indicadores
 * PROPIOS de cada compañía (company_id real) — ver GlobalOrOwnCompanyScope:
 * una compañía nunca ve ni puede tocar los propios de otra, y ninguna puede
 * tocar el catálogo nacional.
 *
 * "Indicadores de impuesto" es el nombre de cara al usuario (antes "Tarifas
 * de IVA") — el modelo/tabla siguen llamándose TaxRate/tax_rates por dentro,
 * es solo una etiqueta distinta en la UI y en los mensajes.
 *
 * *Global() vive detrás del guard 'propietario' (panel del Propietario, ver
 * Backoffice/TaxRates/Index.vue): nadie de una compañía individual debería
 * poder alterar el catálogo fiscal de todas las demás. store/update/destroy
 * (sin sufijo) son su equivalente para el catálogo PROPIO de la compañía
 * activa — index() sigue siendo de lectura+escritura combinada: ve ambos
 * niveles, pero solo puede escribir sobre el suyo (ver Tax/Rates.vue).
 */
class TaxRateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tax/Rates', $this->ratesPayload());
    }

    public function backofficeIndex(): Response
    {
        return Inertia::render('Backoffice/TaxRates/Index', $this->ratesPayload());
    }

    private function ratesPayload(): array
    {
        $usedTaxRateIds = JournalDetailTax::distinct()->pluck('tax_rate_id');

        $rates = TaxRate::with('taxType:id,code,name,company_id')
            ->orderByDesc('effective_from')
            ->orderBy('code')
            ->get(['id', 'company_id', 'tax_type_id', 'code', 'name', 'percentage', 'grants_fiscal_credit', 'fiscal_credit_note', 'effective_from', 'effective_to'])
            ->map(fn (TaxRate $rate) => [
                'id' => $rate->id,
                'company_id' => $rate->company_id,
                'is_global' => $rate->company_id === null,
                'tax_type_id' => $rate->tax_type_id,
                'tax_type' => $rate->taxType,
                'code' => $rate->code,
                'name' => $rate->name,
                'percentage' => $rate->percentage,
                'grants_fiscal_credit' => $rate->grants_fiscal_credit,
                'fiscal_credit_note' => $rate->fiscal_credit_note,
                'effective_from' => $rate->effective_from->format('Y-m-d'),
                'effective_to' => $rate->effective_to?->format('Y-m-d'),
                // Un indicador ya usado en algún asiento contabilizado solo
                // admite cerrarle la vigencia, no reescribir su porcentaje —
                // el formulario de edición se adapta según esta bandera.
                'in_use' => $usedTaxRateIds->contains($rate->id),
            ]);

        return [
            'rates' => $rates,
            'taxTypes' => TaxType::orderBy('code')->get(['id', 'company_id', 'code', 'name'])
                ->map(fn (TaxType $type) => [
                    'id' => $type->id,
                    'company_id' => $type->company_id,
                    'is_global' => $type->company_id === null,
                    'code' => $type->code,
                    'name' => $type->name,
                ]),
        ];
    }

    /**
     * Crea/edita/borra un indicador PROPIO de la compañía activa — nunca
     * toca el catálogo nacional (company_id siempre el de $currentCompany).
     */
    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'tax_type_id' => [
                'nullable', 'integer',
                Rule::exists('tax_types', 'id')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)),
            ],
            'new_tax_type_code' => ['required_without:tax_type_id', 'nullable', 'string', 'max:50'],
            'new_tax_type_name' => ['required_without:tax_type_id', 'nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('tax_rates', 'code')->where(fn ($q) => $q->where('company_id', $companyId))],
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'grants_fiscal_credit' => ['boolean'],
            'fiscal_credit_note' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $taxTypeId = $validated['tax_type_id'] ?? null;

        if (! $taxTypeId) {
            $request->validate([
                'new_tax_type_code' => ['required', 'string', 'max:50', Rule::unique('tax_types', 'code')->where(fn ($q) => $q->where('company_id', $companyId))],
            ]);

            $taxTypeId = TaxType::create([
                'company_id' => $companyId,
                'code' => $validated['new_tax_type_code'],
                'name' => $validated['new_tax_type_name'],
            ])->id;
        }

        TaxRate::create([
            'company_id' => $companyId,
            'tax_type_id' => $taxTypeId,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'percentage' => $validated['percentage'],
            'grants_fiscal_credit' => $validated['grants_fiscal_credit'] ?? false,
            'fiscal_credit_note' => $validated['fiscal_credit_note'] ?? null,
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
        ]);

        return back()->with('success', "Indicador propio {$validated['code']} creado.");
    }

    public function update(Request $request, int $taxRate, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $rate = TaxRate::findOrFail($taxRate);

        // El scope ya deja ver el catálogo nacional (company_id NULL) acá
        // también — pero esta acción es solo para lo propio de la compañía.
        abort_if($rate->company_id !== $companyId, 404);

        if (JournalDetailTax::where('tax_rate_id', $rate->id)->exists()) {
            $validated = $request->validate([
                'effective_to' => ['nullable', 'date', 'after_or_equal:'.$rate->effective_from->format('Y-m-d')],
            ]);

            $rate->update($validated);

            return back()->with('success', "Vigencia del indicador {$rate->code} actualizada.");
        }

        $validated = $request->validate([
            'tax_type_id' => [
                'required', 'integer',
                Rule::exists('tax_types', 'id')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)),
            ],
            'code' => ['required', 'string', 'max:50', Rule::unique('tax_rates', 'code')->where(fn ($q) => $q->where('company_id', $companyId))->ignore($rate->id)],
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'grants_fiscal_credit' => ['boolean'],
            'fiscal_credit_note' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $rate->update($validated);

        return back()->with('success', "Indicador {$rate->code} actualizado.");
    }

    public function destroy(int $taxRate, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $rate = TaxRate::findOrFail($taxRate);

        abort_if($rate->company_id !== $companyId, 404);

        if (JournalDetailTax::where('tax_rate_id', $rate->id)->exists()) {
            return back()->withErrors([
                'tax_rate' => "El indicador {$rate->code} ya se usó en asientos contabilizados; no se puede eliminar. Podés cerrarle la vigencia en su lugar.",
            ]);
        }

        if (ChartOfAccount::where('tax_rate_id', $rate->id)->exists()) {
            return back()->withErrors([
                'tax_rate' => "El indicador {$rate->code} está vinculado a una o más cuentas contables; desvinculalo primero.",
            ]);
        }

        $rate->delete();

        return back()->with('success', "Indicador {$rate->code} eliminado.");
    }

    /**
     * Crea/edita/borra en el catálogo NACIONAL (company_id NULL) — solo
     * alcanzable vía el panel del Propietario.
     */
    public function storeGlobal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tax_type_id' => ['required', 'integer', Rule::exists('tax_types', 'id')->whereNull('company_id')],
            'code' => ['required', 'string', 'max:50', Rule::unique('tax_rates', 'code')->where(fn ($q) => $q->whereNull('company_id'))],
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'grants_fiscal_credit' => ['boolean'],
            'fiscal_credit_note' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        TaxRate::create($validated);

        return back()->with('success', "Indicador {$validated['code']} creado.");
    }

    public function updateGlobal(Request $request, int $taxRate): RedirectResponse
    {
        $rate = TaxRate::findOrFail($taxRate);

        // Un indicador histórico (ya usado en algún asiento contabilizado) no
        // se reescribe: solo se le puede cerrar la vigencia. Para el nuevo
        // porcentaje se crea una fila nueva — mismo criterio que ya
        // documenta TaxRate::isEffectiveOn() y que ahora hace cumplir
        // PostJournalService al contabilizar (ver docs/decisiones.md). El
        // derecho a crédito fiscal se trata igual que el porcentaje/código:
        // parte del registro histórico, no se corrige después de usado.
        if (JournalDetailTax::where('tax_rate_id', $rate->id)->exists()) {
            $validated = $request->validate([
                'effective_to' => ['nullable', 'date', 'after_or_equal:'.$rate->effective_from->format('Y-m-d')],
            ]);

            $rate->update($validated);

            return back()->with('success', "Vigencia del indicador {$rate->code} actualizada.");
        }

        $validated = $request->validate([
            'tax_type_id' => ['required', 'integer', Rule::exists('tax_types', 'id')->whereNull('company_id')],
            'code' => ['required', 'string', 'max:50', Rule::unique('tax_rates', 'code')->where(fn ($q) => $q->whereNull('company_id'))->ignore($rate->id)],
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'grants_fiscal_credit' => ['boolean'],
            'fiscal_credit_note' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $rate->update($validated);

        return back()->with('success', "Indicador {$rate->code} actualizado.");
    }

    public function destroyGlobal(int $taxRate): RedirectResponse
    {
        $rate = TaxRate::findOrFail($taxRate);

        if (JournalDetailTax::where('tax_rate_id', $rate->id)->exists()) {
            return back()->withErrors([
                'tax_rate' => "El indicador {$rate->code} ya se usó en asientos contabilizados; no se puede eliminar. Podés cerrarle la vigencia en su lugar.",
            ]);
        }

        // TaxRate es catálogo nacional (company_id NULL acá): hay que revisar
        // las cuentas de TODAS las compañías, no solo la activa en esta
        // sesión (que ni siquiera existe en el panel del Propietario),
        // bypaseando CompanyScope explícitamente (mismo criterio que
        // PostJournalService documenta para lecturas que no dependen de
        // CurrentCompany ambiental).
        if (ChartOfAccount::withoutGlobalScope(CompanyScope::class)->where('tax_rate_id', $rate->id)->exists()) {
            return back()->withErrors([
                'tax_rate' => "El indicador {$rate->code} está vinculado a una o más cuentas contables; desvinculalo primero.",
            ]);
        }

        $rate->delete();

        return back()->with('success', "Indicador {$rate->code} eliminado.");
    }
}
