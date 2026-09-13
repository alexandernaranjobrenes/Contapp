<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\FxRevaluationRun;
use App\Domains\Accounting\Services\FxRevaluationService;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FxRevaluationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('FxRevaluation/Create', $this->formProps());
    }

    /**
     * Calcula sin contabilizar nada (mismo espíritu que la pantalla de
     * criterios de SAP B1: "Ejecutar" primero muestra el resultado, recién
     * "Crear" en la pantalla siguiente contabiliza). JSON, mismo patrón que
     * LedgerController::show().
     */
    public function preview(Request $request, CurrentCompany $currentCompany, FxRevaluationService $service): JsonResponse
    {
        $validated = $this->validatedCriteria($request);
        $company = Company::findOrFail($currentCompany->id());

        $rows = $service->preview(
            $company,
            new \DateTime($validated['cutoff_date']),
            $validated['include_accounts'],
            $validated['include_business_partners'],
            $validated['account_ids'] ?? null,
            $validated['business_partner_ids'] ?? null,
        );

        return response()->json([
            'closing_rate' => $service->closingRate($company, new \DateTime($validated['cutoff_date'])),
            'rows' => $this->decorateRows($rows),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany, FxRevaluationService $service): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'cutoff_date' => ['required', 'date'],
            'document_type_id' => ['required', 'integer'],
            'gain_account_id' => ['required', 'integer'],
            'loss_account_id' => ['required', 'integer'],
            'selected_groups' => ['required', 'array', 'min:1'],
            'selected_groups.*.account_id' => ['required', 'integer'],
            'selected_groups.*.business_partner_id' => ['nullable', 'integer'],
        ]);

        $company = Company::findOrFail($companyId);
        $documentType = DocumentType::findOrFail($validated['document_type_id']);
        $gainAccount = ChartOfAccount::findOrFail($validated['gain_account_id']);
        $lossAccount = ChartOfAccount::findOrFail($validated['loss_account_id']);

        try {
            $run = $service->execute(
                $company,
                $documentType,
                new \DateTime($validated['cutoff_date']),
                $gainAccount,
                $lossAccount,
                $validated['selected_groups'],
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['revaluation' => $e->getMessage()]);
        }

        if (! $run->journal_entry_id) {
            return back()->with('success', 'No había diferencial cambiario que registrar para lo seleccionado.');
        }

        return redirect()->route('journal-entries.show', $run->journal_entry_id)
            ->with('success', "Diferencial cambiario {$documentType->code}-{$run->journalEntry->document_number} contabilizado.");
    }

    public function index(): Response
    {
        $runs = FxRevaluationRun::with(['documentType:id,code', 'gainAccount:id,code', 'lossAccount:id,code', 'executedBy:id,name'])
            ->whereNotNull('journal_entry_id')
            ->latest('cutoff_date')
            ->get();

        return Inertia::render('FxRevaluation/Index', [
            'runs' => $runs->map(fn (FxRevaluationRun $run) => [
                'id' => $run->id,
                'cutoff_date' => $run->cutoff_date->format('Y-m-d'),
                'exchange_rate_used' => (string) $run->exchange_rate_used,
                'document_type_code' => $run->documentType->code,
                'gain_account' => $run->gainAccount,
                'loss_account' => $run->lossAccount,
                'journal_entry_id' => $run->journal_entry_id,
                'executed_by' => $run->executedBy?->name,
                'executed_at' => $run->executed_at?->format('Y-m-d H:i'),
            ]),
        ]);
    }

    public function show(int $run): Response
    {
        $run = FxRevaluationRun::with([
            'documentType:id,code,name',
            'gainAccount:id,code,description_es',
            'lossAccount:id,code,description_es',
            'executedBy:id,name',
            'details.account:id,code,description_es',
            'details.businessPartner:id,code,name',
        ])->findOrFail($run);

        return Inertia::render('FxRevaluation/Show', [
            'run' => [
                'id' => $run->id,
                'cutoff_date' => $run->cutoff_date->format('Y-m-d'),
                'exchange_rate_used' => (string) $run->exchange_rate_used,
                'document_type' => $run->documentType,
                'gain_account' => $run->gainAccount,
                'loss_account' => $run->lossAccount,
                'journal_entry_id' => $run->journal_entry_id,
                'executed_by' => $run->executedBy?->name,
                'executed_at' => $run->executed_at?->format('Y-m-d H:i'),
                'details' => $run->details->map(fn ($d) => [
                    'account' => $d->account,
                    'business_partner' => $d->businessPartner,
                    'foreign_balance' => (string) $d->foreign_balance,
                    'historical_local_amount' => (string) $d->historical_local_amount,
                    'revalued_local_amount' => (string) $d->revalued_local_amount,
                    'difference' => (string) $d->difference,
                ]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(): array
    {
        return [
            'accounts' => ChartOfAccount::whereIn('currency_mode', ['foreign', 'both'])
                ->where('accepts_posting', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es', 'account_type']),
            'gainLossAccounts' => ChartOfAccount::where('accepts_posting', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es']),
            'businessPartners' => BusinessPartner::where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'category_id']),
            'bpCategories' => BpCategory::orderBy('code')->get(['id', 'code', 'name']),
            'documentTypes' => DocumentType::where('status', 'active')
                ->where('generates_journal', true)
                ->where('is_opening_type', false)
                ->where('is_reconciliation_type', false)
                ->where('is_closing_type', false)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCriteria(Request $request): array
    {
        $validated = $request->validate([
            'cutoff_date' => ['required', 'date'],
            'include_accounts' => ['boolean'],
            'include_business_partners' => ['boolean'],
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['integer'],
            'business_partner_ids' => ['nullable', 'array'],
            'business_partner_ids.*' => ['integer'],
        ]);

        // request->boolean() con default: sin esto, si el front no manda la
        // llave (ej. primera carga antes de tocar los checkboxes), quedaría
        // ausente del array validado en vez de asumir "incluir todo".
        $validated['include_accounts'] = $request->boolean('include_accounts', true);
        $validated['include_business_partners'] = $request->boolean('include_business_partners', true);

        return $validated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function decorateRows(array $rows): array
    {
        $accountIds = collect($rows)->pluck('account_id')->unique();
        $partnerIds = collect($rows)->pluck('business_partner_id')->filter()->unique();

        $accounts = ChartOfAccount::whereIn('id', $accountIds)->get(['id', 'code', 'description_es'])->keyBy('id');
        $partners = BusinessPartner::whereIn('id', $partnerIds)->get(['id', 'code', 'name'])->keyBy('id');

        return array_map(function (array $row) use ($accounts, $partners) {
            $account = $accounts->get($row['account_id']);
            $partner = $row['business_partner_id'] ? $partners->get($row['business_partner_id']) : null;

            return [
                ...$row,
                'account_code' => $account?->code,
                'account_name' => $account?->description_es,
                'business_partner_code' => $partner?->code,
                'business_partner_name' => $partner?->name,
            ];
        }, $rows);
    }
}
