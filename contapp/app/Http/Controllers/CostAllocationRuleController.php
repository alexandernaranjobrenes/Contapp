<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Services\CostAllocationSplitter;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CostAllocationRuleController extends Controller
{
    public function index(CurrentCompany $currentCompany): Response
    {
        return Inertia::render('CostAllocationRules/Index', [
            'rules' => CostAllocationRule::with('lines.costCenter:id,code,name')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'valid_from', 'valid_until', 'is_active']),
            'costCenters' => CostCenter::where('company_id', $currentCompany->id())
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany, CostAllocationSplitter $splitter): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $validated = $this->validated($request, $companyId);

        if (CostAllocationRule::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe una norma de reparto con el código {$validated['code']}."])->withInput();
        }

        if (! $this->linesSumTo100($validated['lines'])) {
            return back()->withErrors(['lines' => 'Los porcentajes de la norma deben sumar exactamente 100%.'])->withInput();
        }

        $rule = CostAllocationRule::create([
            'company_id' => $companyId,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'valid_from' => $validated['valid_from'],
            'valid_until' => $validated['valid_until'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->replaceLines($rule, $validated['lines']);

        return back()->with('success', "Norma de reparto {$rule->code} creada.");
    }

    public function update(Request $request, int $costAllocationRule): RedirectResponse
    {
        $rule = CostAllocationRule::findOrFail($costAllocationRule);
        $validated = $this->validated($request, $rule->company_id);

        if (! $this->linesSumTo100($validated['lines'])) {
            return back()->withErrors(['lines' => 'Los porcentajes de la norma deben sumar exactamente 100%.'])->withInput();
        }

        $rule->update([
            'name' => $validated['name'],
            'valid_from' => $validated['valid_from'],
            'valid_until' => $validated['valid_until'] ?? null,
            'is_active' => $validated['is_active'] ?? false,
        ]);

        $this->replaceLines($rule, $validated['lines']);

        return back()->with('success', "Norma de reparto {$rule->code} actualizada.");
    }

    public function destroy(int $costAllocationRule): RedirectResponse
    {
        $rule = CostAllocationRule::findOrFail($costAllocationRule);

        // Nada contabilizado se borra (regla innegociable): una norma que ya
        // generó movimientos queda permanente, solo se inactiva.
        if (JournalDetail::where('cost_allocation_rule_id', $rule->id)->exists()) {
            return back()->withErrors([
                'rule' => "La norma de reparto {$rule->code} ya generó movimientos contabilizados; no se puede eliminar. Podés inactivarla en su lugar.",
            ]);
        }

        $rule->delete();

        return back()->with('success', "Norma de reparto {$rule->code} eliminada.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $companyId): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.cost_center_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('cost_centers', 'id')->where('company_id', $companyId),
            ],
            'lines.*.percentage' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);
    }

    /**
     * @param  array<int, array{cost_center_id: int, percentage: string|float}>  $lines
     */
    private function linesSumTo100(array $lines): bool
    {
        $sum = array_reduce($lines, fn (string $carry, array $l) => bcadd($carry, (string) $l['percentage'], 2), '0.00');

        return bccomp($sum, '100.00', 2) === 0;
    }

    /**
     * @param  array<int, array{cost_center_id: int, percentage: string|float}>  $lines
     */
    private function replaceLines(CostAllocationRule $rule, array $lines): void
    {
        $rule->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $rule->lines()->create([
                'cost_center_id' => $line['cost_center_id'],
                'percentage' => $line['percentage'],
                'position' => $index + 1,
            ]);
        }
    }
}
