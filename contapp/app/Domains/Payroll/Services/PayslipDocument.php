<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollEntryLine;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * El comprobante de pago como documento: los datos y el PDF.
 *
 * ── Una sola fuente para las cuatro salidas ──────────────────────────────
 *
 * El comprobante se ve en pantalla, se imprime, se descarga en PDF y se manda
 * por correo. Antes la pantalla armaba sus datos en el controlador; si el PDF
 * y el correo hubieran armado los suyos, habría tres versiones del mismo
 * documento y la que el trabajador recibe por correo podría no coincidir con
 * la que firma en papel.
 *
 * Así que los datos se arman UNA vez, acá, y las cuatro salidas los consumen.
 * Es el mismo argumento de ReportResult un nivel más abajo.
 *
 * ── Lo que un comprobante tiene que poder responder ─────────────────────
 *
 * No es un recibo del neto: es el documento con el que el trabajador verifica
 * su propio rebajo. Para eso muestra la BASE y la TASA de cada carga, no solo
 * el monto. «CCSS ₡108.300» no permite comprobar nada; «IVM 4,33% sobre
 * ₡1.000.000» sí.
 */
class PayslipDocument
{
    public function __construct(private readonly MediaStorage $media) {}

    /**
     * Los datos del comprobante.
     *
     * @return array<string, mixed>
     */
    public function payload(Company $company, PayrollEntry $entry): array
    {
        $entry->loadMissing(['employee', 'employee.costCenter:id,code,name', 'period', 'lines']);

        $employee = $entry->employee;

        $lines = fn (array $kinds) => $entry->lines
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
                'logo_url' => $this->media->versionedUrl($company->logo_path, $company->logo_updated_at),
            ],
            'employee' => [
                'code' => $employee?->code,
                'name' => $employee?->fullName(),
                'identification' => $employee?->identification_number,
                'ccss_number' => $employee?->ccss_number,
                'email' => $employee?->email,
                'position' => $employee?->positionLabel(),
                'department' => $employee?->departmentLabel(),
                'cost_center' => $employee?->costCenter === null
                    ? null
                    : $employee->costCenter->code.' — '.$employee->costCenter->name,
                'hire_date' => $employee?->hire_date->format('Y-m-d'),
                'photo_url' => $this->media->url($employee?->photo_path),
                'bank_account' => $entry->bank_account,
                'payment_method' => $entry->payment_method,
            ],
            'period' => [
                'id' => $entry->period?->id,
                'name' => $entry->period?->name,
                'start_date' => $entry->period?->start_date->format('Y-m-d'),
                'end_date' => $entry->period?->end_date->format('Y-m-d'),
                'payment_date' => $entry->period?->payment_date->format('Y-m-d'),
                'status' => $entry->period?->status,
            ],
            'entry' => [
                'id' => $entry->id,
                'days_worked' => (float) $entry->days_worked,
                'base_salary' => $entry->base_salary,
                'total_earnings' => $entry->total_earnings,
                'ccss_base' => $entry->ccss_base,
                'income_tax_base' => $entry->income_tax_base,
                'total_employee_contributions' => $entry->total_employee_contributions,
                'income_tax' => $entry->income_tax,
                'total_other_deductions' => $entry->total_other_deductions,
                'total_deductions' => $entry->total_deductions,
                'net_pay' => $entry->net_pay,
                'total_employer_contributions' => $entry->total_employer_contributions,
                'total_provisions' => $entry->total_provisions,
                'employer_cost' => $entry->employerCost(),
            ],
            'earnings' => $lines(['earning']),
            'deductions' => $lines(PayrollEntryLine::DEDUCTION_KINDS),
            'employerLines' => $lines(PayrollEntryLine::EMPLOYER_KINDS),
        ];
    }

    /** El PDF, listo para descargar, imprimir o adjuntar. */
    public function pdf(Company $company, PayrollEntry $entry): \Barryvdh\DomPDF\PDF
    {
        $payload = $this->payload($company, $entry);

        // El logo va incrustado: dompdf no baja imágenes por HTTP, así que
        // una URL le saldría en blanco. Se agrega acá y no en payload()
        // porque hay que bajarlo del disco, y la pantalla no lo necesita.
        $payload['company']['logo_data_uri'] = $this->media->dataUri($company->logo_path);

        return Pdf::loadView('reports.payslip', $payload)
            ->setPaper('letter', 'portrait');
    }

    /**
     * Nombre del archivo. Lleva el código del trabajador y el período porque
     * es lo que se busca en una carpeta con cien comprobantes.
     */
    public function filename(PayrollEntry $entry): string
    {
        $entry->loadMissing(['employee', 'period']);

        return 'comprobante-'.
            str($entry->employee?->code ?? (string) $entry->employee_id)->slug().'-'.
            str($entry->period?->name ?? (string) $entry->payroll_period_id)->slug().'.pdf';
    }

    /**
     * La boleta de una compañía, cargada sin el scope de compañía.
     *
     * Hace falta para el envío por correo: un job de cola corre sin compañía
     * ambiental, y el scope —que falla cerrado— devolvería nada. El filtro por
     * compañía se hace acá explícitamente, con la boleta atada a su período.
     */
    public function findEntry(Company $company, int $entryId): PayrollEntry
    {
        $entry = PayrollEntry::with(['employee', 'period', 'lines'])->find($entryId);

        if ($entry === null || $entry->period?->company_id !== $company->id) {
            throw new InvalidPayrollException('Esa boleta no existe en esta compañía.');
        }

        return $entry;
    }
}
