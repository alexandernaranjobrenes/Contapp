<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\AccountReconciliation;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Services\AccountReconciliationService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountReconciliationController extends Controller
{
    public function index(int $account, CurrentCompany $currentCompany): Response
    {
        $account = ChartOfAccount::findOrFail($account);
        $companyId = $currentCompany->id();

        $unreconciled = JournalDetail::where('account_id', $account->id)
            ->whereNull('business_partner_id')
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->withSum('accountReconciliationLines as reconciled_amount', 'amount')
            ->with(['journalEntry:id,document_type_id,document_number,posting_date', 'journalEntry.documentType:id,code'])
            ->orderBy('created_at')
            ->get(['id', 'journal_entry_id', 'description', 'debit_local', 'credit_local'])
            ->map(function (JournalDetail $d) {
                $reconciled = number_format((float) ($d->reconciled_amount ?? 0), 2, '.', '');
                $available = bcsub($d->originalAmount(), $reconciled, 2);

                return bccomp($available, '0.00', 2) > 0 ? [
                    'id' => $d->id,
                    'posting_date' => $d->journalEntry->posting_date->format('Y-m-d'),
                    'document' => "{$d->journalEntry->documentType->code}-{$d->journalEntry->document_number}",
                    'description' => $d->description,
                    'debit_local' => (string) $d->debit_local,
                    'credit_local' => (string) $d->credit_local,
                    'available_amount' => $available,
                ] : null;
            })
            ->filter()
            ->values();

        $reconciliations = AccountReconciliation::where('account_id', $account->id)
            ->with(['reconciledBy:id,name', 'lines.journalDetail:id,journal_entry_id,description,debit_local,credit_local', 'lines.journalDetail.journalEntry:id,document_type_id,document_number,posting_date', 'lines.journalDetail.journalEntry.documentType:id,code'])
            ->latest('reconciled_at')
            ->get()
            ->map(fn (AccountReconciliation $r) => [
                'id' => $r->id,
                'reconciled_at' => $r->reconciled_at->format('Y-m-d H:i'),
                'reconciled_by' => $r->reconciledBy?->name,
                'lines' => $r->lines->map(fn ($l) => [
                    'id' => $l->journalDetail->id,
                    'posting_date' => $l->journalDetail->journalEntry->posting_date->format('Y-m-d'),
                    'document' => "{$l->journalDetail->journalEntry->documentType->code}-{$l->journalDetail->journalEntry->document_number}",
                    'description' => $l->journalDetail->description,
                    'debit_local' => (string) $l->journalDetail->debit_local,
                    'credit_local' => (string) $l->journalDetail->credit_local,
                    'amount' => (string) $l->amount,
                    'is_partial' => bccomp((string) $l->amount, $l->journalDetail->originalAmount(), 2) < 0,
                ]),
            ]);

        return Inertia::render('AccountReconciliation/Index', [
            'account' => $account->only(['id', 'code', 'description_es']),
            'unreconciled' => $unreconciled,
            'reconciliations' => $reconciliations,
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->where('id', '!=', $account->id)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es']),
            'businessPartners' => BusinessPartner::where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'gl_account_id']),
            'currencies' => Currency::all(['id', 'code']),
        ]);
    }

    public function store(Request $request, int $account, AccountReconciliationService $service): RedirectResponse
    {
        $account = ChartOfAccount::findOrFail($account);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.journal_detail_id' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $service->reconcile($account, $validated['lines'], $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['reconciliation' => $e->getMessage()]);
        }

        return back()->with('success', 'Movimientos reconciliados.');
    }

    public function destroy(int $reconciliation, AccountReconciliationService $service): RedirectResponse
    {
        $reconciliation = AccountReconciliation::findOrFail($reconciliation);
        $service->unreconcile($reconciliation);

        return back()->with('success', 'Reconciliación deshecha.');
    }

    /**
     * Traspaso entre cuentas para poder reconciliar después dentro de la
     * misma cuenta (docs/decisiones.md 2026-08-24): dos líneas normales vía
     * PostJournalService::post(), con el tipo de documento reservado ARR.
     * Esto NO reconcilia nada por sí solo — deja la línea nueva de $account
     * lista para juntarla con el movimiento suelto original en una
     * reconciliación aparte (POST a account-reconciliation.store).
     */
    public function transfer(Request $request, int $account, CurrentCompany $currentCompany, PostJournalService $postJournalService): RedirectResponse
    {
        $account = ChartOfAccount::findOrFail($account);
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'direction' => ['required', 'in:debit,credit'],
            'target_type' => ['required', 'in:account,partner'],
            'target_account_id' => [
                'required_if:target_type,account', 'nullable', 'integer',
                Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('accepts_posting', true),
            ],
            'target_business_partner_id' => [
                'required_if:target_type,partner', 'nullable', 'integer',
                Rule::exists('business_partners', 'id')->where('company_id', $companyId),
            ],
            'currency_id' => ['required', 'integer'],
        ]);

        $company = Company::findOrFail($companyId);
        $isDebit = $validated['direction'] === 'debit';

        if ($validated['target_type'] === 'account') {
            $targetAccountId = (int) $validated['target_account_id'];
            $targetPartnerId = null;
        } else {
            $partner = BusinessPartner::findOrFail($validated['target_business_partner_id']);

            if (! $partner->gl_account_id) {
                return back()->withErrors(['target_business_partner_id' => "El socio {$partner->code} no tiene cuenta contable de control asociada."]);
            }

            $targetAccountId = $partner->gl_account_id;
            $targetPartnerId = $partner->id;
        }

        $documentType = $this->reconciliationDocumentType($company);

        try {
            $entry = $postJournalService->post(
                $company,
                $documentType,
                new \DateTime($validated['posting_date']),
                new \DateTime($validated['posting_date']),
                [
                    new JournalLineInput(
                        $account->id, (int) $validated['currency_id'],
                        debit: $isDebit ? $validated['amount'] : 0,
                        credit: $isDebit ? 0 : $validated['amount'],
                    ),
                    new JournalLineInput(
                        $targetAccountId, (int) $validated['currency_id'],
                        debit: $isDebit ? 0 : $validated['amount'],
                        credit: $isDebit ? $validated['amount'] : 0,
                        businessPartnerId: $targetPartnerId,
                    ),
                ],
                $validated['description'] ?? "Traspaso de reconciliación — {$account->code}",
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['reconciliation' => $e->getMessage()]);
        }

        return redirect()->route('account-reconciliation.index', $account->id)
            ->with('success', "Traspaso {$documentType->code}-{$entry->document_number} contabilizado. Ya podés reconciliarlo con el movimiento original.");
    }

    /**
     * Atajo de un solo clic: "este movimiento se digitó en la cuenta
     * equivocada" — a diferencia de transfer(), acá el usuario no digita
     * monto/dirección/moneda (se toman del movimiento original que
     * seleccionó), solo indica la cuenta o socio correcto. Internamente hace
     * el mismo traspaso ARR que transfer() y de una vez reconcilia ambas
     * líneas, así que el movimiento original desaparece de "No reconciliados"
     * en un solo paso.
     */
    public function reclassify(Request $request, int $account, CurrentCompany $currentCompany, PostJournalService $postJournalService, AccountReconciliationService $service): RedirectResponse
    {
        $account = ChartOfAccount::findOrFail($account);
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'journal_detail_id' => ['required', 'integer'],
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'target_type' => ['required', 'in:account,partner'],
            'target_account_id' => [
                'required_if:target_type,account', 'nullable', 'integer',
                Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('accepts_posting', true),
            ],
            'target_business_partner_id' => [
                'required_if:target_type,partner', 'nullable', 'integer',
                Rule::exists('business_partners', 'id')->where('company_id', $companyId),
            ],
        ]);

        $detail = JournalDetail::where('account_id', $account->id)
            ->whereNull('business_partner_id')
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->find($validated['journal_detail_id']);

        if (! $detail || bccomp($detail->availableReconciliationAmount(), '0.00', 2) <= 0) {
            return back()->withErrors(['reconciliation' => 'El movimiento indicado no existe, no está contabilizado, o ya está completamente reconciliado.']);
        }

        $company = Company::findOrFail($companyId);

        if ($validated['target_type'] === 'account') {
            $targetAccountId = (int) $validated['target_account_id'];
            $targetPartnerId = null;
        } else {
            $partner = BusinessPartner::findOrFail($validated['target_business_partner_id']);

            if (! $partner->gl_account_id) {
                return back()->withErrors(['target_business_partner_id' => "El socio {$partner->code} no tiene cuenta contable de control asociada."]);
            }

            $targetAccountId = $partner->gl_account_id;
            $targetPartnerId = $partner->id;
        }

        $documentType = $this->reconciliationDocumentType($company);

        try {
            $service->reclassify(
                $postJournalService, $company, $documentType, $account, $detail,
                $targetAccountId, $targetPartnerId,
                new \DateTime($validated['posting_date']), $validated['description'] ?? null,
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['reconciliation' => $e->getMessage()]);
        }

        return back()->with('success', 'Movimiento reclasificado y reconciliado.');
    }

    /**
     * Mismo criterio que OpeningBalanceBulkImporter::openingDocumentType():
     * un tipo de documento reservado por compañía, creado de oficio la
     * primera vez que hace falta, código fijo "ARR".
     */
    private function reconciliationDocumentType(Company $company): DocumentType
    {
        return DocumentType::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ARR'],
            [
                'name' => 'Asiento de reconciliación',
                'origin_module' => 'contable',
                'generates_journal' => true,
                'currency_mode' => 'libre',
                'is_reconciliation_type' => true,
                'status' => 'active',
            ],
        );
    }
}
