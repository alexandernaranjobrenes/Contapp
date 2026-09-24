<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;

/**
 * El archivo de pago para la banca: una línea por trabajador con su cuenta y
 * su neto, para subirlo al portal del banco en vez de digitar doscientas
 * transferencias.
 *
 * ── Por qué es CSV y no un formato propietario ───────────────────────────
 *
 * Cada banco de la plaza pide su propio formato, y ninguno lo publica de
 * forma estable: cambian las columnas, el separador, el ancho de los campos
 * y el orden, a veces sin aviso. Inventar aquí un formato fijo para "el
 * banco" produciría un archivo que un banco rechaza y otro acepta a medias.
 *
 * Lo que sí es igual en todos es el CONTENIDO: cédula, nombre, cuenta,
 * monto, referencia. Este servicio genera ese contenido en un CSV delimitado
 * y ordenado, que es lo que todos los portales aceptan importar y lo que
 * cualquier persona puede reacomodar en Excel en un minuto si su banco pide
 * otro orden.
 *
 * ── Lo que NO va en el archivo ───────────────────────────────────────────
 *
 * Nadie que cobre en efectivo o por cheque: el archivo es de
 * transferencias. Incluirlos haría que el banco los transfiriera igual, a
 * una cuenta vacía o ajena, y que además se les pagara en caja.
 *
 * ── La validación que evita el problema caro ─────────────────────────────
 *
 * Un trabajador marcado como transferencia pero SIN cuenta se rechaza antes
 * de generar el archivo, con su nombre. La alternativa —omitirlo en
 * silencio— produce el error que nadie detecta hasta que esa persona llama
 * a decir que no le llegó el salario.
 */
class PayrollBankFileBuilder
{
    public const HEADERS = [
        'Identificacion', 'Nombre', 'Cuenta', 'Monto', 'Moneda', 'Referencia',
    ];

    /**
     * @return array{filename: string, contents: string, rows: int, total: string}
     */
    public function build(Company $company, PayrollPeriod $period, string $currencyCode = 'CRC'): array
    {
        if (! in_array($period->status, ['approved', 'posted', 'closed'], true)) {
            throw new InvalidPayrollException(
                "El período «{$period->name}» todavía no está aprobado. ".
                'Generar el archivo de pago antes de aprobar la planilla arriesga pagar un cálculo que aún puede cambiar.'
            );
        }

        $entries = PayrollEntry::with('employee')
            ->where('payroll_period_id', $period->id)
            ->get()
            ->filter(fn (PayrollEntry $e) => bccomp((string) $e->net_pay, '0.00', 2) > 0);

        if ($entries->isEmpty()) {
            throw new InvalidPayrollException("El período «{$period->name}» no tiene netos que pagar.");
        }

        $transfers = $entries->filter(fn (PayrollEntry $e) => $e->payment_method === 'transferencia');

        $missing = $transfers->filter(fn (PayrollEntry $e) => blank($e->bank_account));

        if ($missing->isNotEmpty()) {
            $names = $missing->map(fn (PayrollEntry $e) => $e->employee?->fullName() ?? "id {$e->employee_id}")
                ->implode(', ');

            throw new InvalidPayrollException(
                "Estos trabajadores están marcados para pago por transferencia y no tienen cuenta bancaria: {$names}. ".
                'Completá la cuenta en su ficha o cambiales la forma de pago antes de generar el archivo.'
            );
        }

        $rows = [implode(';', self::HEADERS)];
        $total = '0.00';

        foreach ($transfers->sortBy(fn (PayrollEntry $e) => $e->employee?->code) as $entry) {
            $employee = $entry->employee;

            $rows[] = implode(';', [
                $this->clean($employee?->identification_number ?? ''),
                $this->clean($employee?->fullName() ?? ''),
                $this->clean($entry->bank_account ?? ''),
                // Punto decimal y sin separador de miles: es lo que todos
                // los portales leen, y un separador de miles convierte el
                // monto en texto que el banco rechaza o trunca.
                number_format((float) $entry->net_pay, 2, '.', ''),
                $currencyCode,
                $this->clean("Planilla {$period->name}"),
            ]);

            $total = bcadd($total, (string) $entry->net_pay, 2);
        }

        return [
            'filename' => 'pago-planilla-'.str($period->name)->slug().'.csv',
            'contents' => implode("\r\n", $rows)."\r\n",
            'rows' => count($rows) - 1,
            'total' => $total,
        ];
    }

    /**
     * Quita el separador y los saltos de línea del contenido.
     *
     * Un apellido con punto y coma partiría la fila en dos y correría todas
     * las columnas siguientes: el banco leería el monto de una persona en el
     * campo de cuenta de otra.
     */
    private function clean(string $value): string
    {
        return trim(str_replace([';', "\r", "\n", '"'], ' ', $value));
    }
}
