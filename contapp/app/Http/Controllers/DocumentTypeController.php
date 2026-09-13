<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DocumentTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('DocumentTypes/Index', [
            // select() antes de withCount(): así el conteo se agrega encima
            // de la selección explícita en vez de competir por cuál gana
            // (get($columnas) no pisa un $columns ya fijado por withCount).
            'documentTypes' => DocumentType::select(['id', 'code', 'name', 'origin_module', 'generates_journal', 'currency_mode', 'status', 'next_consecutive', 'is_opening_type', 'is_reconciliation_type', 'is_closing_type'])
                ->withCount('numberSeries')
                ->orderBy('code')
                ->get(),
            'originModules' => DocumentType::ORIGIN_MODULES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('DocumentTypes/Create', [
            'accounts' => $this->postingAccounts(),
            'originModules' => DocumentType::ORIGIN_MODULES,
            'currencyModes' => DocumentType::CURRENCY_MODES,
            'bpLineRequirements' => DocumentType::BP_LINE_REQUIREMENTS,
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/',
                Rule::unique('document_types')->where('company_id', $companyId),
            ],
            'starting_consecutive' => ['nullable', 'integer', 'min:1'],
            ...$this->sharedRules($companyId),
        ], [
            'code.regex' => 'El código debe ser exactamente 3 letras (ej. FVE, TRB).',
        ]);

        DocumentType::create([
            ...$validated,
            'company_id' => $companyId,
            'code' => strtoupper($validated['code']),
            'next_consecutive' => $validated['starting_consecutive'] ?? 1,
        ]);

        return redirect()->route('document-types.index')->with('success', "Tipo de documento {$validated['code']} creado.");
    }

    public function edit(int $documentType): Response
    {
        $documentType = DocumentType::with(['numberSeries' => fn ($q) => $q->orderBy('name')])->findOrFail($documentType);

        return Inertia::render('DocumentTypes/Edit', [
            'documentType' => $documentType,
            'accounts' => $this->postingAccounts(),
            'originModules' => DocumentType::ORIGIN_MODULES,
            'currencyModes' => DocumentType::CURRENCY_MODES,
            'bpLineRequirements' => DocumentType::BP_LINE_REQUIREMENTS,
        ]);
    }

    /**
     * code y next_consecutive no se aceptan acá a propósito: el código
     * identifica al tipo de documento en cada asiento ya contabilizado
     * ("FVE-123"), cambiarlo reescribiría cómo se ve el historial; el
     * consecutivo interno es, por definición, inalterable una vez creado.
     */
    public function update(Request $request, int $documentType, CurrentCompany $currentCompany): RedirectResponse
    {
        $documentType = DocumentType::findOrFail($documentType);
        $validated = $request->validate($this->sharedRules($currentCompany->id()));

        $documentType->update($validated);

        return back()->with('success', "Tipo de documento {$documentType->code} actualizado.");
    }

    private function postingAccounts()
    {
        return ChartOfAccount::where('accepts_posting', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'description_es']);
    }

    private function sharedRules(?int $companyId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'origin_module' => ['required', Rule::in(array_keys(DocumentType::ORIGIN_MODULES))],
            'generates_journal' => ['boolean'],
            'requires_electronic_key' => ['boolean'],
            // Sin 'required': igual que generates_journal/requires_electronic_key,
            // si no se manda se queda con el default de columna ('none') al
            // crear, o no se toca el valor existente al editar.
            'bp_line_requirement' => [Rule::in(array_keys(DocumentType::BP_LINE_REQUIREMENTS))],
            'currency_mode' => ['required', Rule::in(array_keys(DocumentType::CURRENCY_MODES))],
            'default_debit_account_id' => [
                'nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('accepts_posting', true),
            ],
            'default_credit_account_id' => [
                'nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('accepts_posting', true),
            ],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
