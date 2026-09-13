<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\CostAllocationRuleLine;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CostCenterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('CostCenters/Index', [
            'costCenters' => CostCenter::orderBy('code')->get([
                'id', 'code', 'name', 'start_date', 'end_date', 'is_active',
            ]),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
        ]);

        if (CostCenter::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe un centro de costo con el código {$validated['code']}."])->withInput();
        }

        CostCenter::create([
            'company_id' => $companyId,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', "Centro de costo {$validated['code']} creado.");
    }

    /**
     * El código no se edita una vez creado: puede estar referenciado por
     * código en la columna norma_reparto de las plantillas xlsx de
     * importación. Solo se corrigen nombre, vigencia y estado.
     */
    public function update(Request $request, int $costCenter): RedirectResponse
    {
        $costCenter = CostCenter::findOrFail($costCenter);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
        ]);

        $costCenter->update([
            ...$validated,
            'is_active' => $validated['is_active'] ?? false,
        ]);

        return back()->with('success', "Centro de costo {$costCenter->code} actualizado.");
    }

    public function destroy(int $costCenter): RedirectResponse
    {
        $costCenter = CostCenter::findOrFail($costCenter);

        // Nada contabilizado se borra (regla innegociable): un centro con
        // movimientos, aunque sea uno solo, queda permanente.
        if (JournalDetail::where('cost_center_id', $costCenter->id)->exists()) {
            return back()->withErrors([
                'cost_center' => "El centro de costo {$costCenter->code} ya tiene movimientos contabilizados; no se puede eliminar. Podés inactivarlo en su lugar.",
            ]);
        }

        $ruleLine = CostAllocationRuleLine::where('cost_center_id', $costCenter->id)
            ->with('costAllocationRule:id,code')
            ->first();

        if ($ruleLine) {
            return back()->withErrors([
                'cost_center' => "El centro de costo {$costCenter->code} está referenciado en la norma de reparto {$ruleLine->costAllocationRule->code}; quitalo de ahí primero, o inactivá el centro de costo en su lugar.",
            ]);
        }

        $costCenter->delete();

        return back()->with('success', "Centro de costo {$costCenter->code} eliminado.");
    }
}
