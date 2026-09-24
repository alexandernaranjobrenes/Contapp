<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Services\PayrollBankFileBuilder;
use App\Domains\Payroll\Services\PayrollReportExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las salidas de la planilla: el XLSX de tres hojas y el archivo de pago.
 */
class PayrollReportController extends Controller
{
    public function export(int $payrollPeriod, PayrollReportExporter $exporter): StreamedResponse
    {
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $period),
            'planilla-'.str($period->name)->slug().'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function bankFile(
        int $payrollPeriod,
        PayrollBankFileBuilder $builder,
        CurrentCompany $currentCompany,
    ): HttpResponse|RedirectResponse {
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        try {
            $file = $builder->build(Company::findOrFail($currentCompany->id()), $period);
        } catch (InvalidPayrollException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return response($file['contents'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }
}
