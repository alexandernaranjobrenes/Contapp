<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Services\ChartOfAccountBulkImporter;
use App\Domains\Accounting\Services\ChartOfAccountTemplateExporter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChartOfAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = ChartOfAccount::orderBy('code')->get([
            'id', 'code', 'description_es', 'description_en', 'account_type', 'normal_balance',
            'currency_mode', 'accepts_posting', 'requires_business_partner', 'is_cash_account',
            'requires_cost_center', 'tax_classification', 'tax_rate_id', 'is_active',
        ]);

        return Inertia::render('ChartOfAccounts/Index', [
            'accounts' => $accounts,
            'accountTypes' => ChartOfAccount::ACCOUNT_TYPES,
            // Para el selector "Indicador de impuesto vinculado": no filtra
            // por vigencia acá, es solo configuración de a qué cuenta apunta
            // cada indicador — la vigencia real se exige recién al contabilizar.
            'taxRates' => TaxRate::orderBy('code')->get(['id', 'code', 'name', 'percentage']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $this->validated($request, $companyId);

        ChartOfAccount::create([
            ...$validated,
            'company_id' => $companyId,
            'level' => 1,
            'normal_balance' => ChartOfAccount::normalBalanceFor($validated['account_type']),
        ]);

        return back()->with('success', "Cuenta {$validated['code']} creada.");
    }

    public function update(Request $request, int $account, CurrentCompany $currentCompany): RedirectResponse
    {
        $account = ChartOfAccount::findOrFail($account);
        $validated = $this->validated($request, $currentCompany->id(), $account->id);

        $account->update([
            ...$validated,
            'normal_balance' => ChartOfAccount::normalBalanceFor($validated['account_type']),
        ]);

        return back()->with('success', "Cuenta {$account->code} actualizada.");
    }

    public function destroy(int $account): RedirectResponse
    {
        $account = ChartOfAccount::findOrFail($account);

        // Nada contabilizado se borra (regla innegociable): una cuenta con
        // movimientos, aunque sea uno solo, queda permanente. Solo se puede
        // dar de baja una cuenta que nunca se usó.
        if (JournalDetail::where('account_id', $account->id)->exists()) {
            return back()->withErrors([
                'account' => "La cuenta {$account->code} ya tiene movimientos contabilizados; no se puede eliminar. Podés inactivarla en su lugar.",
            ]);
        }

        if (ChartOfAccount::where('parent_id', $account->id)->exists()) {
            return back()->withErrors([
                'account' => "La cuenta {$account->code} tiene subcuentas asociadas; eliminá esas primero.",
            ]);
        }

        $account->delete();

        return back()->with('success', "Cuenta {$account->code} eliminada.");
    }

    public function template(CurrentCompany $currentCompany, ChartOfAccountTemplateExporter $exporter): StreamedResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $company),
            'catalogo-cuentas.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function import(Request $request, CurrentCompany $currentCompany, ChartOfAccountBulkImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx'],
        ]);

        $company = Company::findOrFail($currentCompany->id());

        $result = $importer->import($request->file('file')->getRealPath(), $company);

        if ($result->hasErrors()) {
            return back()->with('importErrors', $result->errors);
        }

        return back()->with('success', "Se importaron {$result->importedCount} cuenta(s) del archivo.");
    }

    private function validated(Request $request, ?int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('chart_of_accounts')->where('company_id', $companyId)->ignore($ignoreId),
            ],
            'description_es' => ['required', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:255'],
            'account_type' => ['required', Rule::in(array_keys(ChartOfAccount::ACCOUNT_TYPES))],
            'currency_mode' => ['required', Rule::in(array_keys(ChartOfAccount::CURRENCY_MODES))],
            'tax_classification' => ['required', Rule::in(array_keys(ChartOfAccount::TAX_CLASSIFICATIONS))],
            // Nacional (company_id NULL) o propio de ESTA compañía — nunca de
            // otra (TaxRate ahora admite indicadores propios por compañía,
            // ver GlobalOrOwnCompanyScope).
            'tax_rate_id' => [
                'nullable', 'integer',
                Rule::exists('tax_rates', 'id')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)),
            ],
            'accepts_posting' => ['boolean'],
            'requires_business_partner' => ['boolean'],
            'is_cash_account' => ['boolean'],
            'requires_cost_center' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
