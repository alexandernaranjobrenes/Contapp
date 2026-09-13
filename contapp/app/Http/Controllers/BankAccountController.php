<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Banking/Index', [
            'bankAccounts' => BankAccount::with('glAccount:id,code,description_es', 'currency:id,code,symbol')
                ->orderBy('bank_name')
                ->get(),
            ...$this->formProps(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Banking/Create', $this->formProps());
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $this->validated($request, $companyId);

        BankAccount::create($validated);

        return redirect()->route('bank-accounts.index')->with('success', 'Cuenta bancaria creada.');
    }

    public function update(Request $request, int $bankAccount, CurrentCompany $currentCompany): RedirectResponse
    {
        $account = BankAccount::findOrFail($bankAccount);
        $validated = $this->validated($request, $currentCompany->id(), $account->id);

        $account->update($validated);

        return redirect()->route('bank-accounts.index')->with('success', 'Cuenta bancaria actualizada.');
    }

    private function validated(Request $request, ?int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
            'gl_account_id' => [
                'required', 'integer',
                Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('is_cash_account', true),
                Rule::unique('bank_accounts', 'gl_account_id')->ignore($ignoreId),
            ],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(): array
    {
        return [
            // Solo cuentas marcadas como "cuenta monetaria" en el catálogo
            // (ChartOfAccountController) son elegibles para representar un
            // banco/caja — ver docs/decisiones.md 2026-08-14.
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->where('is_cash_account', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es']),
            'currencies' => Currency::all(['id', 'code', 'symbol']),
        ];
    }
}
