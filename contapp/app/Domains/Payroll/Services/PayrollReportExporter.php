<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollEntryLine;
use App\Domains\Payroll\Models\PayrollPeriod;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * La planilla del período en XLSX, en tres hojas.
 *
 * ── Por qué tres hojas y no una ──────────────────────────────────────────
 *
 * Porque son tres documentos distintos que se usan en tres momentos:
 *
 *   «Planilla»  el resumen por trabajador. Es el que se firma y archiva.
 *   «Detalle»   cada línea de cada boleta, con su base y su tasa. Es el
 *               que sirve cuando alguien reclama un rebajo.
 *   «CCSS»      solo lo que se le reporta a la Caja, componente por
 *               componente. Es el que se concilia contra la planilla que
 *               devuelve la institución.
 *
 * Meterlo todo en una hoja obliga a filtrar a mano cada vez, y la hoja de
 * CCSS es justamente la que tiene que poder mandarse sin el resto: lleva
 * bases salariales, no netos ni préstamos.
 */
class PayrollReportExporter
{
    public function writeTo(string $outputPath, PayrollPeriod $period): void
    {
        $entries = PayrollEntry::with(['employee', 'costCenter:id,code,name', 'lines'])
            ->where('payroll_period_id', $period->id)
            ->get()
            ->sortBy(fn (PayrollEntry $e) => $e->employee?->code)
            ->values();

        $writer = new Writer;
        $writer->openToFile($outputPath);

        $bold = (new Style)->setFontBold();
        $header = (new Style)->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $this->summarySheet($writer, $period, $entries, $bold, $header);
        $this->detailSheet($writer, $period, $entries, $bold, $header);
        $this->ccssSheet($writer, $period, $entries, $bold, $header);

        $writer->close();
    }

    private function summarySheet(Writer $writer, PayrollPeriod $period, $entries, Style $bold, Style $header): void
    {
        $writer->getCurrentSheet()->setName('Planilla');

        $writer->addRows([
            Row::fromValues(["Planilla {$period->name}"], $bold),
            Row::fromValues([
                "Del {$period->start_date->format('Y-m-d')} al {$period->end_date->format('Y-m-d')}",
                "Pago: {$period->payment_date->format('Y-m-d')}",
                'Estado: '.(PayrollPeriod::STATUSES[$period->status] ?? $period->status),
            ]),
            Row::fromValues([]),
            Row::fromValues([
                'Código', 'Trabajador', 'Cédula', 'Puesto', 'Centro de costo', 'Días',
                'Salario bruto', 'Base CCSS', 'Cargas obreras', 'Impuesto', 'Otras deducciones',
                'Total deducciones', 'Neto a pagar',
                'Cargas patronales', 'Provisiones', 'Costo total empresa',
            ], $header),
        ]);

        foreach ($entries as $entry) {
            $writer->addRow(Row::fromValues([
                $entry->employee?->code,
                $entry->employee?->fullName(),
                $entry->employee?->identification_number,
                $entry->employee?->position,
                $entry->costCenter?->code,
                (float) $entry->days_worked,
                (float) $entry->total_earnings,
                (float) $entry->ccss_base,
                (float) $entry->total_employee_contributions,
                (float) $entry->income_tax,
                (float) $entry->total_other_deductions,
                (float) $entry->total_deductions,
                (float) $entry->net_pay,
                (float) $entry->total_employer_contributions,
                (float) $entry->total_provisions,
                (float) $entry->employerCost(),
            ]));
        }

        $sum = fn (string $field) => (float) $entries->reduce(
            fn ($carry, PayrollEntry $e) => bcadd($carry, (string) $e->{$field}, 2), '0.00'
        );

        $writer->addRows([
            Row::fromValues([]),
            Row::fromValues([
                'TOTALES', (string) $entries->count().' trabajador(es)', '', '', '', '',
                $sum('total_earnings'), $sum('ccss_base'), $sum('total_employee_contributions'),
                $sum('income_tax'), $sum('total_other_deductions'), $sum('total_deductions'),
                $sum('net_pay'), $sum('total_employer_contributions'), $sum('total_provisions'),
                (float) $entries->reduce(fn ($c, PayrollEntry $e) => bcadd($c, $e->employerCost(), 2), '0.00'),
            ], $bold),
        ]);
    }

    private function detailSheet(Writer $writer, PayrollPeriod $period, $entries, Style $bold, Style $header): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Detalle');

        $writer->addRows([
            Row::fromValues(["Detalle de planilla {$period->name}"], $bold),
            Row::fromValues(['Cada línea con la base y la tasa que se le aplicaron, congeladas al calcular.']),
            Row::fromValues([]),
            Row::fromValues([
                'Código', 'Trabajador', 'Tipo', 'Concepto', 'Descripción',
                'Cantidad', 'Base', 'Tasa %', 'Monto',
            ], $header),
        ]);

        foreach ($entries as $entry) {
            foreach ($entry->lines->sortBy('line_number') as $line) {
                $writer->addRow(Row::fromValues([
                    $entry->employee?->code,
                    $entry->employee?->fullName(),
                    PayrollEntryLine::KINDS[$line->kind] ?? $line->kind,
                    $line->code,
                    $line->name,
                    $line->quantity === null ? null : (float) $line->quantity,
                    $line->base_amount === null ? null : (float) $line->base_amount,
                    $line->rate === null ? null : (float) $line->rate,
                    (float) $line->amount,
                ]));
            }
        }
    }

    /**
     * Lo que se le reporta a la Caja: salario reportado y cada componente de
     * carga, separando obrero de patronal.
     *
     * Va sin netos ni préstamos a propósito: esta hoja se manda fuera, y el
     * rebajo de pensión alimentaria de un trabajador no es asunto de la
     * institución que recibe las cuotas.
     */
    private function ccssSheet(Writer $writer, PayrollPeriod $period, $entries, Style $bold, Style $header): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('CCSS');

        // Los códigos de carga que aparecieron, para armar una columna por
        // componente. Se derivan de las boletas y no de la configuración
        // actual: una carga que se dejó de usar no debe aparecer, y una que
        // regía entonces y ya no, sí.
        $codes = $entries
            ->flatMap(fn (PayrollEntry $e) => $e->lines
                ->whereIn('kind', ['employee_contribution', 'employer_contribution'])
                ->map(fn (PayrollEntryLine $l) => [$l->code => $l->name]))
            ->reduce(fn (array $carry, array $pair) => $carry + $pair, []);

        ksort($codes);

        $writer->addRows([
            Row::fromValues(["Planilla CCSS — {$period->name}"], $bold),
            Row::fromValues(['Bases salariales y cuotas por componente. No incluye deducciones personales ni netos.']),
            Row::fromValues([]),
            Row::fromValues([
                'Cédula', 'Asegurado', 'Trabajador', 'Días', 'Salario reportado',
                ...array_map(fn (string $code) => "{$code} — {$codes[$code]}", array_keys($codes)),
            ], $header),
        ]);

        foreach ($entries as $entry) {
            $byCode = $entry->lines
                ->whereIn('kind', ['employee_contribution', 'employer_contribution'])
                ->keyBy('code');

            $writer->addRow(Row::fromValues([
                $entry->employee?->identification_number,
                $entry->employee?->ccss_number,
                $entry->employee?->fullName(),
                (float) $entry->days_worked,
                // La base reportada es la de CCSS, no el bruto: son
                // distintas en cuanto hay un viático o un subsidio, y
                // reportar el bruto sobredeclara ante la institución.
                (float) $entry->ccss_base,
                ...array_map(
                    fn (string $code) => $byCode->has($code) ? (float) $byCode[$code]->amount : 0.0,
                    array_keys($codes)
                ),
            ]));
        }
    }
}
