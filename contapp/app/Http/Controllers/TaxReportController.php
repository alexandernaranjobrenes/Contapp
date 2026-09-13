<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Tax\Services\TaxReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxReportController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, TaxReportService $service): Response
    {
        $from = $request->query('from', now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', now()->endOfMonth()->format('Y-m-d'));

        $company = Company::findOrFail($currentCompany->id());

        return Inertia::render('Tax/Report', [
            'from' => $from,
            'to' => $to,
            'summary' => $service->generate($company, new \DateTime($from), new \DateTime($to)),
        ]);
    }
}
