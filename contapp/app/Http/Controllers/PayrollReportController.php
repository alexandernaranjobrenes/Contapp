<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Services\PayrollBankFileBuilder;
use App\Domains\Payroll\Services\PayrollInputWorkbook;
use App\Domains\Payroll\Services\PayrollReportExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las salidas de la planilla: el XLSX de tres hojas y el archivo de pago.
 */
class PayrollReportController extends Controller
{
    /**
     * La plantilla de movimientos del período: sale llena con los empleados
     * que entran y lo que ya esté digitado.
     */
    public function inputsTemplate(
        int $payrollPeriod,
        PayrollInputWorkbook $workbook,
        CurrentCompany $currentCompany,
    ): StreamedResponse {
        $period = PayrollPeriod::findOrFail($payrollPeriod);
        $company = Company::findOrFail($currentCompany->id());

        return response()->streamDownload(
            fn () => $workbook->template($company, $period, 'php://output'),
            'movimientos-'.str($period->name)->slug().'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    /**
     * Carga el archivo lleno. REEMPLAZA los movimientos del período: ver el
     * encabezado de PayrollInputWorkbook.
     */
    public function importInputs(
        Request $request,
        int $payrollPeriod,
        PayrollInputWorkbook $workbook,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx']]);

        $result = $workbook->import(
            Company::findOrFail($currentCompany->id()),
            $period,
            $request->file('file')->getRealPath(),
        );

        if ($result->failed()) {
            return back()->withErrors([
                'payroll_import' => $result->fatal ?? 'El archivo tiene errores y no se cargó nada.',
                // Los errores por celda van aparte: la pantalla los lista
                // para poder corregirlos de una pasada en vez de uno por uno.
                ...collect($result->errors)->mapWithKeys(fn ($e, $i) => ["payroll_import_{$i}" => $e])->all(),
            ]);
        }

        return back()->with('success', $result->summary());
    }

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
