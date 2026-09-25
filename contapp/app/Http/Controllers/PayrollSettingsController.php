<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\PayrollTaxBracket;
use App\Domains\Payroll\Models\PayrollTaxCredit;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La configuración de planilla: cuentas, cargas sociales, escala del
 * impuesto, créditos familiares, provisiones y conceptos.
 *
 * Todo lo que acá se edita lleva VIGENCIA, y eso no es un detalle: una
 * planilla de marzo se calcula con las tasas que regían en marzo. Cambiar
 * una tasa no reescribe el pasado — crea una vigencia nueva.
 */
class PayrollSettingsController extends Controller
{
    public function __construct(private readonly CostaRicaPayrollDefaults $defaults) {}

    public function index(CurrentCompany $currentCompany): Response
    {
        $settings = PayrollSetting::where('company_id', $currentCompany->id())->first();

        return Inertia::render('Payroll/Settings/Index', [
            'settings' => $settings === null ? null : [
                'id' => $settings->id,
                'salary_expense_account_id' => $settings->salary_expense_account_id,
                'net_payable_account_id' => $settings->net_payable_account_id,
                'income_tax_payable_account_id' => $settings->income_tax_payable_account_id,
                'document_type_id' => $settings->document_type_id,
                'vacation_days_per_month' => (float) $settings->vacation_days_per_month,
                'max_deduction_percentage' => (float) $settings->max_deduction_percentage,
            ],
            'contributions' => PayrollContribution::orderBy('payer')->orderBy('code')
                ->get()
                ->map(fn (PayrollContribution $c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                    'payer' => $c->payer, 'institution' => $c->institution,
                    'percentage' => (float) $c->percentage, 'base' => $c->base,
                    'ceiling_amount' => $c->ceiling_amount,
                    'expense_account_id' => $c->expense_account_id,
                    'liability_account_id' => $c->liability_account_id,
                    'valid_from' => $c->valid_from->format('Y-m-d'),
                    'valid_to' => $c->valid_to?->format('Y-m-d'),
                    'status' => $c->status, 'legal_basis' => $c->legal_basis,
                ])->values(),
            'brackets' => PayrollTaxBracket::orderBy('valid_from')->orderBy('bracket_number')
                ->get()
                ->map(fn (PayrollTaxBracket $b) => [
                    'id' => $b->id, 'bracket_number' => $b->bracket_number,
                    'from_amount' => $b->from_amount, 'to_amount' => $b->to_amount,
                    'percentage' => (float) $b->percentage,
                    'valid_from' => $b->valid_from->format('Y-m-d'),
                    'valid_to' => $b->valid_to?->format('Y-m-d'),
                ])->values(),
            'credits' => PayrollTaxCredit::orderBy('valid_from')->orderBy('code')
                ->get()
                ->map(fn (PayrollTaxCredit $c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                    'monthly_amount' => $c->monthly_amount,
                    'valid_from' => $c->valid_from->format('Y-m-d'),
                    'valid_to' => $c->valid_to?->format('Y-m-d'),
                ])->values(),
            'provisions' => PayrollProvision::orderBy('valid_from')->orderBy('code')
                ->get()
                ->map(fn (PayrollProvision $p) => [
                    'id' => $p->id, 'code' => $p->code, 'name' => $p->name,
                    'percentage' => (float) $p->percentage,
                    'expense_account_id' => $p->expense_account_id,
                    'liability_account_id' => $p->liability_account_id,
                    'valid_from' => $p->valid_from->format('Y-m-d'),
                    'valid_to' => $p->valid_to?->format('Y-m-d'),
                    'status' => $p->status, 'legal_basis' => $p->legal_basis,
                ])->values(),
            'concepts' => PayrollConcept::orderBy('type')->orderBy('code')
                ->get()
                ->map(fn (PayrollConcept $c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type,
                    'affects_ccss' => (bool) $c->affects_ccss,
                    'affects_income_tax' => (bool) $c->affects_income_tax,
                    'affects_provisions' => (bool) $c->affects_provisions,
                    'calculation' => $c->calculation,
                    'factor' => $c->factor === null ? null : (float) $c->factor,
                    'account_id' => $c->account_id,
                    'status' => $c->status, 'legal_basis' => $c->legal_basis,
                ])->values(),
            'accounts' => ChartOfAccount::where('accepts_posting', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es', 'account_type']),
            'documentTypes' => DocumentType::where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name']),
            'payers' => PayrollContribution::PAYERS,
            'institutions' => PayrollContribution::INSTITUTIONS,
            // Que la plantilla existe no significa que esté verificada: la
            // pantalla lo dice, y acá se le pasa el dato para que no dependa
            // de que alguien lo recuerde.
            'hasConfiguration' => PayrollContribution::exists(),
        ]);
    }

    /**
     * Carga la plantilla costarricense de arranque.
     *
     * Los valores que entran NO son una fuente autorizada: son un punto de
     * partida con vigencia, para verificar contra el decreto y corregir.
     */
    public function loadDefaults(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $validated = $request->validate([
            'valid_from' => ['required', 'date'],
        ]);

        $counts = $this->defaults->load(
            Company::findOrFail($currentCompany->id()),
            $validated['valid_from']
        );

        return back()->with('success',
            "Plantilla cargada con vigencia {$validated['valid_from']}: ".
            "{$counts['contributions']} cargas, {$counts['brackets']} tramos de impuesto, ".
            "{$counts['credits']} créditos, {$counts['provisions']} provisiones y {$counts['concepts']} conceptos. ".
            'Verificá cada porcentaje contra el decreto vigente antes de correr la primera planilla.'
        );
    }

    /**
     * Guarda TODAS las cuentas de la planilla de una sola vez.
     *
     * ── Por qué existe este método aparte ────────────────────────────────
     *
     * Las cuentas de la planilla viven repartidas: unas en la configuración
     * de la compañía, dos en cada componente de carga social, dos en cada
     * provisión y una en cada concepto. Eso está bien como modelo —cada
     * cuenta pertenece a lo que la usa— y es inservible como pantalla:
     * obliga a abrir sesenta modales para contestar una sola pregunta, que
     * es «¿a qué cuentas va a caer mi planilla?».
     *
     * Esta pantalla responde esa pregunta en una tabla y guarda todo junto,
     * en una transacción. Las pantallas por fila siguen existiendo para lo
     * demás (tasas, vigencias, banderas); acá solo se tocan cuentas.
     */
    public function updateAccounts(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $accountRule = ['nullable', Rule::exists('chart_of_accounts', 'id')
            ->where('company_id', $companyId)
            ->where('accepts_posting', true)];

        $validated = $request->validate([
            'settings' => ['array'],
            'settings.salary_expense_account_id' => $accountRule,
            'settings.net_payable_account_id' => $accountRule,
            'settings.income_tax_payable_account_id' => $accountRule,

            'contributions' => ['array'],
            'contributions.*.id' => ['required', Rule::exists('payroll_contributions', 'id')->where('company_id', $companyId)],
            'contributions.*.expense_account_id' => $accountRule,
            'contributions.*.liability_account_id' => $accountRule,

            'provisions' => ['array'],
            'provisions.*.id' => ['required', Rule::exists('payroll_provisions', 'id')->where('company_id', $companyId)],
            'provisions.*.expense_account_id' => $accountRule,
            'provisions.*.liability_account_id' => $accountRule,

            'concepts' => ['array'],
            'concepts.*.id' => ['required', Rule::exists('payroll_concepts', 'id')->where('company_id', $companyId)],
            'concepts.*.account_id' => $accountRule,
        ]);

        DB::transaction(function () use ($validated, $companyId) {
            // Se guarda todo o nada: dejar la mitad de las cuentas asignadas
            // produce una planilla que falla a mitad de contabilizar, con
            // parte del asiento ya armado.
            if (isset($validated['settings'])) {
                PayrollSetting::updateOrCreate(
                    ['company_id' => $companyId],
                    $validated['settings']
                );
            }

            foreach ($validated['contributions'] ?? [] as $row) {
                PayrollContribution::where('id', $row['id'])->update([
                    'expense_account_id' => $row['expense_account_id'] ?? null,
                    'liability_account_id' => $row['liability_account_id'] ?? null,
                ]);
            }

            foreach ($validated['provisions'] ?? [] as $row) {
                PayrollProvision::where('id', $row['id'])->update([
                    'expense_account_id' => $row['expense_account_id'] ?? null,
                    'liability_account_id' => $row['liability_account_id'] ?? null,
                ]);
            }

            foreach ($validated['concepts'] ?? [] as $row) {
                PayrollConcept::where('id', $row['id'])->update([
                    'account_id' => $row['account_id'] ?? null,
                ]);
            }
        });

        return back()->with('success', 'Determinación de cuentas de planilla guardada.');
    }

    /**
     * Los parámetros del cálculo. Las CUENTAS no pasan por acá: viven en
     * updateAccounts(), que es la pantalla de determinación.
     *
     * Están separados a propósito. Una tasa la fija un decreto y una cuenta la
     * fija el contador: son dos decisiones que se toman en momentos distintos
     * y por personas distintas. Con los dos en el mismo formulario, guardar un
     * cambio de parámetro con el formulario a medio cargar borraría cuentas
     * que nadie quiso tocar.
     */
    public function update(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'document_type_id' => ['nullable', Rule::exists('document_types', 'id')->where('company_id', $companyId)],
            'vacation_days_per_month' => ['required', 'numeric', 'gte:0', 'max:10'],
            // Cero significa sin tope. El máximo es 100 porque un tope mayor
            // que el salario disponible no es un tope.
            'max_deduction_percentage' => ['required', 'numeric', 'gte:0', 'max:100'],
        ]);

        PayrollSetting::updateOrCreate(['company_id' => $companyId], $validated);

        return back()->with('success', 'Configuración de planilla guardada.');
    }

    // ── Cargas sociales ─────────────────────────────────────────────────

    public function storeContribution(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->contributionRules($companyId));

        PayrollContribution::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', "Carga social {$validated['code']} creada.");
    }

    public function updateContribution(Request $request, int $contribution, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = PayrollContribution::findOrFail($contribution);

        $model->update($request->validate($this->contributionRules($currentCompany->id(), $model->id)));

        return back()->with('success', "Carga social {$model->code} actualizada.");
    }

    public function destroyContribution(int $contribution): RedirectResponse
    {
        $model = PayrollContribution::findOrFail($contribution);

        // Una carga ya aplicada en una boleta no se borra: la línea de esa
        // boleta la referencia, y borrarla dejaría la planilla sin poder
        // explicar de dónde salió ese rebajo.
        if ($model->entryLines()->exists()) {
            return back()->withErrors([
                'contribution' => "La carga {$model->code} ya se aplicó en planillas calculadas. ".
                    'Para dejar de usarla, poné una fecha de vigencia final o desactivala.',
            ]);
        }

        $model->delete();

        return back()->with('success', 'Carga social eliminada.');
    }

    // ── Escala del impuesto ─────────────────────────────────────────────

    public function storeBracket(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $validated = $request->validate($this->bracketRules());

        PayrollTaxBracket::create([...$validated, 'company_id' => $currentCompany->id()]);

        return back()->with('success', 'Tramo agregado.');
    }

    public function updateBracket(Request $request, int $bracket): RedirectResponse
    {
        $model = PayrollTaxBracket::findOrFail($bracket);

        $model->update($request->validate($this->bracketRules()));

        return back()->with('success', 'Tramo actualizado.');
    }

    public function destroyBracket(int $bracket): RedirectResponse
    {
        PayrollTaxBracket::findOrFail($bracket)->delete();

        return back()->with('success', 'Tramo eliminado.');
    }

    // ── Créditos familiares ─────────────────────────────────────────────

    public function storeCredit(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $validated = $request->validate($this->creditRules());

        PayrollTaxCredit::create([...$validated, 'company_id' => $currentCompany->id()]);

        return back()->with('success', 'Crédito familiar agregado.');
    }

    public function updateCredit(Request $request, int $credit): RedirectResponse
    {
        $model = PayrollTaxCredit::findOrFail($credit);

        $model->update($request->validate($this->creditRules()));

        return back()->with('success', 'Crédito familiar actualizado.');
    }

    public function destroyCredit(int $credit): RedirectResponse
    {
        PayrollTaxCredit::findOrFail($credit)->delete();

        return back()->with('success', 'Crédito familiar eliminado.');
    }

    // ── Provisiones ─────────────────────────────────────────────────────

    public function storeProvision(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->provisionRules($companyId));

        PayrollProvision::create([...$validated, 'company_id' => $companyId]);

        return back()->with('success', 'Provisión creada.');
    }

    public function updateProvision(Request $request, int $provision, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = PayrollProvision::findOrFail($provision);

        $model->update($request->validate($this->provisionRules($currentCompany->id())));

        return back()->with('success', 'Provisión actualizada.');
    }

    public function destroyProvision(int $provision): RedirectResponse
    {
        PayrollProvision::findOrFail($provision)->delete();

        return back()->with('success', 'Provisión eliminada.');
    }

    // ── Conceptos ───────────────────────────────────────────────────────

    public function storeConcept(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->conceptRules($companyId));

        PayrollConcept::create([...$this->normalizeConcept($validated), 'company_id' => $companyId]);

        return back()->with('success', "Concepto {$validated['code']} creado.");
    }

    public function updateConcept(Request $request, int $concept, CurrentCompany $currentCompany): RedirectResponse
    {
        $model = PayrollConcept::findOrFail($concept);

        $model->update($this->normalizeConcept(
            $request->validate($this->conceptRules($currentCompany->id(), $model->id))
        ));

        return back()->with('success', "Concepto {$model->code} actualizado.");
    }

    public function destroyConcept(int $concept): RedirectResponse
    {
        $model = PayrollConcept::findOrFail($concept);

        if ($model->entryLines()->exists()) {
            return back()->withErrors([
                'concept' => "El concepto {$model->code} ya se usó en planillas calculadas. Desactivalo en vez de borrarlo.",
            ]);
        }

        $model->delete();

        return back()->with('success', 'Concepto eliminado.');
    }

    // ── Reglas ──────────────────────────────────────────────────────────

    /** @return array<string, array<int, mixed>> */
    private function contributionRules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:30',
                // El código se repite entre vigencias —esa es la gracia—
                // pero no dentro de una misma fecha de inicio.
                Rule::unique('payroll_contributions', 'code')
                    ->where('company_id', $companyId)
                    ->where('valid_from', request('valid_from'))
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'payer' => ['required', Rule::in(array_keys(PayrollContribution::PAYERS))],
            'institution' => ['required', Rule::in(array_keys(PayrollContribution::INSTITUTIONS))],
            'percentage' => ['required', 'numeric', 'gte:0', 'max:100'],
            'base' => ['required', Rule::in(['ccss', 'gross'])],
            'ceiling_amount' => ['nullable', 'numeric', 'gt:0'],
            'expense_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'liability_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'legal_basis' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function bracketRules(): array
    {
        return [
            'bracket_number' => ['required', 'integer', 'min:1', 'max:20'],
            'from_amount' => ['required', 'numeric', 'gte:0'],
            // Vacío = el último tramo, sin techo. Si viene, tiene que ser
            // mayor que el piso: un tramo invertido no grava nada y pasaría
            // inadvertido porque el cálculo simplemente lo saltaría.
            'to_amount' => ['nullable', 'numeric', 'gt:from_amount'],
            'percentage' => ['required', 'numeric', 'gte:0', 'max:100'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function creditRules(): array
    {
        return [
            'code' => ['required', Rule::in(['spouse', 'child'])],
            'name' => ['required', 'string', 'max:255'],
            'monthly_amount' => ['required', 'numeric', 'gte:0'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function provisionRules(int $companyId): array
    {
        return [
            'code' => ['required', Rule::in(['aguinaldo', 'vacaciones', 'cesantia', 'preaviso'])],
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'gte:0', 'max:100'],
            'expense_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'liability_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'legal_basis' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function conceptRules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('payroll_concepts', 'code')->where('company_id', $companyId)->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['earning', 'deduction'])],
            // Las tres banderas deciden qué es salario y qué no: son lo más
            // consecuente de toda la configuración.
            'affects_ccss' => ['boolean'],
            'affects_income_tax' => ['boolean'],
            'affects_provisions' => ['boolean'],
            'calculation' => ['required', Rule::in(['amount', 'percentage', 'hours'])],
            'factor' => ['nullable', 'numeric', 'gte:0'],
            'account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'is_recurring' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'legal_basis' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Las casillas desmarcadas llegan ausentes: sin el `?? false` no habría
     * forma de apagar una bandera una vez encendida, y una bandera de estas
     * encendida por error cobra cargas sobre un ingreso que no es salario.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeConcept(array $validated): array
    {
        return [
            ...$validated,
            'affects_ccss' => $validated['affects_ccss'] ?? false,
            'affects_income_tax' => $validated['affects_income_tax'] ?? false,
            'affects_provisions' => $validated['affects_provisions'] ?? false,
            'is_recurring' => $validated['is_recurring'] ?? false,
        ];
    }
}
