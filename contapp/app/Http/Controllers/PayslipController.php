<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollEntryLine;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El comprobante de pago del trabajador.
 *
 * ── Lo que un comprobante tiene que poder responder ──────────────────────
 *
 * No es un recibo del neto. Es el documento con el que el trabajador
 * verifica su propio rebajo, y para eso tiene que mostrar la BASE y la TASA
 * de cada carga, no solo el monto. Un comprobante que dice «CCSS ₡106.700»
 * no permite comprobar nada; uno que dice «IVM 4,17% sobre ₡1.000.000» sí.
 *
 * ── Por qué muestra también lo patronal ──────────────────────────────────
 *
 * Porque no rebaja nada y por eso nadie lo ve. Un trabajador que solo
 * conoce su bruto subestima lo que su puesto le cuesta a la empresa en una
 * proporción grande, y la empresa que solo mira el bruto cotiza mal. Va en
 * un bloque aparte, claramente marcado como que no afecta el neto.
 */
class PayslipController extends Controller
{
    public function show(int $entry, CurrentCompany $currentCompany): Response
    {
        return Inertia::render('Payroll/Payslips/Show', $this->payload($entry, $currentCompany));
    }

    /** La versión para imprimir: el mismo contenido sin la navegación. */
    public function print(int $entry, CurrentCompany $currentCompany): Response
    {
        return Inertia::render('Payroll/Payslips/Print', $this->payload($entry, $currentCompany));
    }

    /** @return array<string, mixed> */
    private function payload(int $entry, CurrentCompany $currentCompany): array
    {
        $model = PayrollEntry::with(['employee', 'employee.costCenter:id,code,name', 'period', 'lines'])
            ->findOrFail($entry);

        $company = Company::findOrFail($currentCompany->id());
        $employee = $model->employee;

        $lines = fn (array $kinds) => $model->lines
            ->whereIn('kind', $kinds)
            ->sortBy('line_number')
            ->map(fn (PayrollEntryLine $l) => [
                'kind' => $l->kind,
                'kind_label' => PayrollEntryLine::KINDS[$l->kind] ?? $l->kind,
                'code' => $l->code,
                'name' => $l->name,
                // La base y la tasa CONGELADAS son lo que hace verificable el
                // comprobante: ver el encabezado.
                'base_amount' => $l->base_amount,
                'rate' => $l->rate === null ? null : (float) $l->rate,
                'quantity' => $l->quantity === null ? null : (float) $l->quantity,
                'amount' => $l->amount,
            ])->values();

        return [
            'company' => [
                'name' => $company->trade_name ?: $company->legal_name,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'logo_url' => $this->publicUrl($company->logo_path),
            ],
            'employee' => [
                'code' => $employee?->code,
                'name' => $employee?->fullName(),
                'identification' => $employee?->identification_number,
                'ccss_number' => $employee?->ccss_number,
                'position' => $employee?->position,
                'department' => $employee?->department,
                'cost_center' => $employee?->costCenter?->code.' — '.$employee?->costCenter?->name,
                'hire_date' => $employee?->hire_date->format('Y-m-d'),
                'photo_url' => $this->publicUrl($employee?->photo_path),
                'bank_account' => $model->bank_account,
                'payment_method' => $model->payment_method,
            ],
            'period' => [
                'id' => $model->period?->id,
                'name' => $model->period?->name,
                'start_date' => $model->period?->start_date->format('Y-m-d'),
                'end_date' => $model->period?->end_date->format('Y-m-d'),
                'payment_date' => $model->period?->payment_date->format('Y-m-d'),
                'status' => $model->period?->status,
            ],
            'entry' => [
                'id' => $model->id,
                'days_worked' => (float) $model->days_worked,
                'base_salary' => $model->base_salary,
                'total_earnings' => $model->total_earnings,
                'ccss_base' => $model->ccss_base,
                'income_tax_base' => $model->income_tax_base,
                'total_employee_contributions' => $model->total_employee_contributions,
                'income_tax' => $model->income_tax,
                'total_other_deductions' => $model->total_other_deductions,
                'total_deductions' => $model->total_deductions,
                'net_pay' => $model->net_pay,
                'total_employer_contributions' => $model->total_employer_contributions,
                'total_provisions' => $model->total_provisions,
                'employer_cost' => $model->employerCost(),
            ],
            'earnings' => $lines(['earning']),
            'deductions' => $lines(PayrollEntryLine::DEDUCTION_KINDS),
            'employerLines' => $lines(PayrollEntryLine::EMPLOYER_KINDS),
        ];
    }

    private function publicUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->url($path) : null;
    }
}
