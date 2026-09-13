<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PeriodCloseService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodCloseController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): Response
    {
        $company = Company::findOrFail($currentCompany->id());

        return Inertia::render('Accounting/PeriodClose', [
            'fiscalYears' => FiscalYear::where('company_id', $company->id)
                ->with('periods')
                ->orderByDesc('year')
                ->get(),
            'equityAccounts' => ChartOfAccount::where('company_id', $company->id)
                ->where('account_type', 'equity')
                ->where('accepts_posting', true)
                ->orderBy('code')
                ->get(['id', 'code', 'description_es']),
            'canReopen' => $request->user()->isSuperAdmin($company->id),
        ]);
    }

    /**
     * Crea el año fiscal siguiente (con sus 12 períodos) para la compañía
     * activa — sin exigir que el año anterior esté cerrado, a propósito
     * (ver PeriodCloseService::createNextYear()).
     */
    public function createYear(CurrentCompany $currentCompany, PeriodCloseService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        $fiscalYear = $service->createNextYear($company);

        return back()->with('success', "Año fiscal {$fiscalYear->year} creado, con sus 12 períodos abiertos.");
    }

    public function close(Request $request, int $period, CurrentCompany $currentCompany, PeriodCloseService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $period = FiscalPeriod::with('fiscalYear')->findOrFail($period);

        // FiscalPeriod no tiene company_id propio (se filtra vía
        // fiscal_year_id -> fiscal_years.company_id), así que sin este
        // chequeo un id de OTRA compañía se resolvería igual. fiscalYear ya
        // viene con CompanyScope aplicado por el eager load: si es de otra
        // compañía, viene null.
        abort_if(! $period->fiscalYear, 404);

        try {
            $service->close($company, $period, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        return back()->with('success', "Período #{$period->period_number} cerrado.");
    }

    public function reopen(Request $request, int $period, CurrentCompany $currentCompany, PeriodCloseService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $period = FiscalPeriod::with('fiscalYear')->findOrFail($period);

        abort_if(! $period->fiscalYear, 404);
        abort_unless($request->user()->can('reopen', $period), 403);

        try {
            $service->reopen($company, $period, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        return back()->with('success', "Período #{$period->period_number} reabierto.");
    }

    public function closeYear(Request $request, int $fiscalYear, CurrentCompany $currentCompany, PeriodCloseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'retained_earnings_account_id' => ['required', 'integer'],
        ]);

        $company = Company::findOrFail($currentCompany->id());
        $fiscalYear = FiscalYear::findOrFail($fiscalYear);
        $retainedEarnings = ChartOfAccount::where('company_id', $company->id)
            ->findOrFail($validated['retained_earnings_account_id']);
        $accDocumentType = $service->closingDocumentType($company);

        try {
            $service->closeYear($company, $fiscalYear, $accDocumentType, $retainedEarnings, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['year' => $e->getMessage()]);
        }

        return back()->with('success', "Año fiscal {$fiscalYear->year} cerrado.");
    }
}
