<?php

namespace App\Http\Controllers;

use App\Domains\Banking\Exceptions\UnbalancedReconciliationException;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankReconciliation;
use App\Domains\Banking\Models\BankReconciliationLine;
use App\Domains\Banking\Services\BankReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los ids de ruta se reciben crudos y se resuelven a mano (no binding
 * implícito de Eloquent), igual que en OpenItemController: ver
 * docs/decisiones.md 2026-08-05 sobre el orden de SetCurrentCompany vs
 * SubstituteBindings.
 */
class BankReconciliationController extends Controller
{
    /**
     * "Hub" de conciliaciones: todas las cuentas bancarias de la compañía
     * con su última conciliación (si tiene), para saltar directo a conciliar
     * cualquiera sin pasar primero por el catálogo de cuentas bancarias.
     */
    public function hub(): Response
    {
        // BankAccount::glAccount()/reconciliations() ya vienen scoped por la
        // compañía activa a través del BelongsToCompany de BankAccount — no
        // hace falta CurrentCompany explícito acá (a diferencia de los
        // services que corren sin request, ver PostJournalService).
        $bankAccounts = BankAccount::with(['glAccount:id,code,description_es', 'reconciliations' => fn ($q) => $q->latest('cutoff_date')->limit(1)])
            ->orderBy('bank_name')
            ->get();

        return Inertia::render('Banking/ReconciliationsHub', [
            'bankAccounts' => $bankAccounts->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'bank_name' => $account->bank_name,
                'account_number' => $account->account_number,
                'gl_account' => $account->glAccount,
                'last_reconciliation' => $account->reconciliations->first(),
            ]),
        ]);
    }

    public function index(int $bankAccount): Response
    {
        $bankAccount = BankAccount::with('glAccount:id,code,description_es')->findOrFail($bankAccount);

        return Inertia::render('Banking/Reconciliations/Index', [
            'bankAccount' => $bankAccount,
            'reconciliations' => BankReconciliation::where('bank_account_id', $bankAccount->id)
                ->orderByDesc('cutoff_date')
                ->get(),
        ]);
    }

    public function create(int $bankAccount): Response
    {
        $bankAccount = BankAccount::findOrFail($bankAccount);

        return Inertia::render('Banking/Reconciliations/Create', [
            'bankAccount' => $bankAccount,
        ]);
    }

    public function store(Request $request, int $bankAccount, BankReconciliationService $service): RedirectResponse
    {
        $bankAccount = BankAccount::findOrFail($bankAccount);

        $validated = $request->validate([
            'cutoff_date' => ['required', 'date'],
            'bank_balance' => ['required', 'numeric'],
        ]);

        $reconciliation = $service->open(
            $bankAccount,
            new \DateTime($validated['cutoff_date']),
            $validated['bank_balance'],
            $request->user()->id,
        );

        return redirect()->route('bank-reconciliations.show', $reconciliation->id);
    }

    public function show(int $reconciliation): Response
    {
        $reconciliation = BankReconciliation::with([
            'bankAccount.glAccount:id,code,description_es',
            'lines.journalDetail.account:id,code,description_es',
        ])->findOrFail($reconciliation);

        // BankReconciliation no tiene company_id propio (se filtra vía
        // bank_account_id -> bank_accounts.company_id); sin este chequeo,
        // un id de OTRA compañía se resolvería igual porque BankReconciliation
        // no tiene CompanyScope. bankAccount ya viene con scope aplicado por
        // el eager load: si es de otra compañía, viene null.
        abort_if(! $reconciliation->bankAccount, 404);

        return Inertia::render('Banking/Reconciliations/Show', [
            'reconciliation' => $reconciliation,
            'adjustedBookBalance' => $reconciliation->adjustedBookBalance(),
            'adjustedBankBalance' => $reconciliation->adjustedBankBalance(),
            'isBalanced' => $reconciliation->isBalanced(),
        ]);
    }

    public function confirmLine(int $reconciliation, int $line, BankReconciliationService $service): RedirectResponse
    {
        $line = BankReconciliationLine::with('bankReconciliation.bankAccount')
            ->where('bank_reconciliation_id', $reconciliation)
            ->findOrFail($line);

        abort_if(! $line->bankReconciliation?->bankAccount, 404);
        // Una conciliación cerrada es historial: hay que reabrirla primero
        // (reopen()) para poder tocar sus checks de nuevo.
        abort_if($line->bankReconciliation->status === 'completed', 404);

        $service->confirmInBank($line);

        return back();
    }

    public function close(int $reconciliation, BankReconciliationService $service): RedirectResponse
    {
        $reconciliation = BankReconciliation::with('bankAccount')->findOrFail($reconciliation);

        abort_if(! $reconciliation->bankAccount, 404);

        try {
            $service->close($reconciliation);
        } catch (UnbalancedReconciliationException $e) {
            return back()->withErrors(['balance' => $e->getMessage()]);
        }

        return back()->with('success', 'Conciliación cerrada.');
    }

    public function reopen(int $reconciliation, BankReconciliationService $service): RedirectResponse
    {
        $reconciliation = BankReconciliation::with('bankAccount')->findOrFail($reconciliation);

        abort_if(! $reconciliation->bankAccount, 404);

        $service->reopen($reconciliation);

        return back()->with('success', 'Conciliación reabierta.');
    }

    public function destroy(int $reconciliation, BankReconciliationService $service): RedirectResponse
    {
        $reconciliation = BankReconciliation::with('bankAccount')->findOrFail($reconciliation);

        abort_if(! $reconciliation->bankAccount, 404);

        $bankAccountId = $reconciliation->bank_account_id;

        $service->delete($reconciliation);

        return redirect()->route('bank-reconciliations.index', $bankAccountId)->with('success', 'Conciliación eliminada.');
    }
}
