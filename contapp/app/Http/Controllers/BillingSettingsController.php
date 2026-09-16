<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Billing\Contracts\HaciendaSigner;
use App\Domains\Billing\Contracts\HaciendaTransport;
use App\Domains\Billing\Models\BillingPaymentAccount;
use App\Domains\Billing\Models\BillingTaxAccount;
use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las tres piezas de configuración que una venta necesita para contabilizarse:
 * a qué cuenta de ingresos va cada actividad económica, contra qué cuenta se
 * acredita el IVA de cada tarifa, y contra cuál se debita cada medio de pago.
 *
 * Viven en el módulo de facturación y no en el catálogo contable ni en la
 * matriz de inventario: son decisiones de facturación, y mezclarlas habría
 * obligado a tocar configuración que ya está en uso.
 */
class BillingSettingsController extends Controller
{
    public function index(HaciendaSigner $signer, HaciendaTransport $transport): Response
    {
        return Inertia::render('Billing/Settings/Index', [
            'activities' => CompanyEconomicActivity::with('revenueAccount:id,code,description_es')
                ->orderBy('code')->get(),
            'taxAccounts' => BillingTaxAccount::with(['account:id,code,description_es', 'taxRate:id,code,percentage'])
                ->orderBy('iva_rate_code')->get(),
            'paymentAccounts' => BillingPaymentAccount::with('account:id,code,description_es')
                ->orderBy('method_code')->get(),
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->orderBy('code')->get(['id', 'code', 'description_es']),
            'taxRates' => TaxRate::orderBy('code')->get(['id', 'code', 'name', 'percentage']),
            'catalogs' => [
                'ivaRates' => FiscalCatalogs::IVA_RATES,
                'paymentMethods' => FiscalCatalogs::PAYMENT_METHODS,
            ],
            'hacienda' => [
                'signer_configured' => $signer->isConfigured(),
                'transport_configured' => $transport->isConfigured(),
            ],
        ]);
    }

    public function storeActivity(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'name' => ['required', 'string', 'max:255'],
            'revenue_account_id' => ['required', $this->accountRule($companyId)],
            'is_default' => ['boolean'],
        ]);

        if (CompanyEconomicActivity::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe la actividad económica {$validated['code']}."])->withInput();
        }

        // Una sola actividad por defecto: marcar una desmarca la anterior.
        if ($validated['is_default'] ?? false) {
            CompanyEconomicActivity::where('company_id', $companyId)->update(['is_default' => false]);
        }

        CompanyEconomicActivity::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', "Actividad económica {$validated['code']} registrada.");
    }

    public function destroyActivity(int $activity): RedirectResponse
    {
        CompanyEconomicActivity::findOrFail($activity)->delete();

        return back()->with('success', 'Actividad económica eliminada.');
    }

    public function storeTaxAccount(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'iva_rate_code' => ['required', Rule::in(array_keys(FiscalCatalogs::IVA_RATES))],
            'account_id' => ['required', $this->accountRule($companyId)],
            'tax_rate_id' => ['nullable', Rule::exists('tax_rates', 'id')],
        ]);

        if (BillingTaxAccount::where('company_id', $companyId)->where('iva_rate_code', $validated['iva_rate_code'])->exists()) {
            return back()->withErrors([
                'iva_rate_code' => 'Esa tarifa ya tiene cuenta configurada; editá la existente en vez de duplicarla.',
            ])->withInput();
        }

        BillingTaxAccount::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', 'Cuenta de IVA configurada.');
    }

    public function destroyTaxAccount(int $taxAccount): RedirectResponse
    {
        BillingTaxAccount::findOrFail($taxAccount)->delete();

        return back()->with('success', 'Cuenta de IVA eliminada.');
    }

    public function storePaymentAccount(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'method_code' => ['required', Rule::in(array_keys(FiscalCatalogs::PAYMENT_METHODS))],
            'account_id' => ['required', $this->accountRule($companyId)],
        ]);

        if (BillingPaymentAccount::where('company_id', $companyId)->where('method_code', $validated['method_code'])->exists()) {
            return back()->withErrors([
                'method_code' => 'Ese medio de pago ya tiene cuenta configurada.',
            ])->withInput();
        }

        BillingPaymentAccount::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', 'Cuenta del medio de pago configurada.');
    }

    public function destroyPaymentAccount(int $paymentAccount): RedirectResponse
    {
        BillingPaymentAccount::findOrFail($paymentAccount)->delete();

        return back()->with('success', 'Cuenta del medio de pago eliminada.');
    }

    /**
     * Guarda de configuración: exigir cuenta hoja acá evita que el error
     * aparezca recién al emitir la primera factura. La autoridad sigue siendo
     * PostJournalService, que lo valida igual.
     */
    private function accountRule(int $companyId): Rule|Exists
    {
        return Rule::exists('chart_of_accounts', 'id')
            ->where('company_id', $companyId)
            ->where('accepts_posting', true);
    }
}
