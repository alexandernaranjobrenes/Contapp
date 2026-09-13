<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Services\OpeningBalanceBulkImporter;
use App\Domains\Accounting\Services\OpeningBalanceTemplateExporter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpeningBalanceController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('OpeningBalance/Create');
    }

    public function template(CurrentCompany $currentCompany, OpeningBalanceTemplateExporter $exporter): StreamedResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $company),
            'saldos-iniciales.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function import(Request $request, CurrentCompany $currentCompany, OpeningBalanceBulkImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:xlsx'],
        ]);

        $company = Company::findOrFail($currentCompany->id());

        $result = $importer->import(
            $request->file('file')->getRealPath(),
            $company,
            new \DateTime($validated['posting_date']),
            $validated['description'] ?? null,
            $request->user()->id,
        );

        if ($result->hasErrors()) {
            return back()->with('importErrors', $result->errors);
        }

        return redirect()->route('journal-entries.show', $result->journalEntryId)
            ->with('success', 'Saldos iniciales cargados: asiento de apertura contabilizado.');
    }
}
