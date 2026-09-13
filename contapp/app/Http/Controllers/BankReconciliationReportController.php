<?php

namespace App\Http\Controllers;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Services\BankReconciliationReportExporter;
use App\Domains\Banking\Services\BankReconciliationReportService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankReconciliationReportController extends Controller
{
    private const MONTH_NAMES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function index(Request $request, CurrentCompany $currentCompany, BankReconciliationReportService $service): Response
    {
        $companyId = $currentCompany->id();
        $validated = $this->validateFilters($request, $companyId);

        $bankAccount = $validated['bank_account_id']
            ? BankAccount::findOrFail($validated['bank_account_id'])
            : null;

        return Inertia::render('Banking/ReconciliationReport', [
            'bankAccounts' => BankAccount::with('glAccount:id,code,description_es')
                ->orderBy('bank_name')
                ->get(['id', 'bank_name', 'account_number', 'gl_account_id']),
            'filters' => $validated,
            'rows' => $bankAccount ? $service->build($bankAccount, $validated['year'], $validated['month']) : [],
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        BankReconciliationReportService $service,
        BankReconciliationReportExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse|\Illuminate\Http\RedirectResponse {
        $companyId = $currentCompany->id();
        $validated = $this->validateFilters($request, $companyId);

        if (! $validated['bank_account_id']) {
            return back()->withErrors(['bank_account_id' => 'Debe seleccionar una cuenta bancaria.']);
        }

        $bankAccount = BankAccount::findOrFail($validated['bank_account_id']);
        $company = Company::findOrFail($companyId);
        $rows = $service->build($bankAccount, $validated['year'], $validated['month']);
        $header = $headerFactory->make(
            $company, $request->user(), 'Conciliaciones bancarias',
            $this->paramsSummary($bankAccount, $validated),
        );

        $fileName = "conciliaciones-{$bankAccount->account_number}-{$validated['year']}-".str_pad((string) $validated['month'], 2, '0', STR_PAD_LEFT).'.xlsx';

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $bankAccount, $rows),
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function validateFilters(Request $request, int $companyId): array
    {
        $validated = $request->validate([
            'bank_account_id' => [
                'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('company_id', $companyId),
            ],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return [
            'bank_account_id' => $validated['bank_account_id'] ?? null,
            'year' => $validated['year'] ?? (int) now()->format('Y'),
            'month' => $validated['month'] ?? (int) now()->format('n'),
        ];
    }

    private function paramsSummary(BankAccount $bankAccount, array $filters): string
    {
        $monthName = self::MONTH_NAMES[$filters['month']];

        return "{$bankAccount->bank_name} — {$bankAccount->account_number} · {$monthName} {$filters['year']}";
    }
}
