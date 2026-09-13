<?php

namespace App\Domains\Tax\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Tax\Models\JournalDetailTax;

/**
 * Borrador de declaración de IVA (D-104), leyendo journal_detail_taxes como
 * snapshot histórico — nunca recalcula desde journal_details en crudo (ver
 * docs/decisiones.md 2026-08-04): una corrección posterior de tarifa no debe
 * reescribir silenciosamente un reporte ya generado.
 *
 * Usa joins SQL directos (no relaciones Eloquent) precisamente para no
 * depender de CompanyScope/CurrentCompany: filtra por company_id explícito.
 */
class TaxReportService
{
    public function generate(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = JournalDetailTax::query()
            ->join('journal_details', 'journal_details.id', '=', 'journal_detail_taxes.journal_detail_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_details.account_id')
            ->where('journal_entries.company_id', $company->id)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.posting_date', '>=', $from->format('Y-m-d'))
            ->whereDate('journal_entries.posting_date', '<=', $to->format('Y-m-d'))
            ->get([
                'chart_of_accounts.tax_classification',
                'journal_detail_taxes.taxable_base',
                'journal_detail_taxes.tax_amount',
            ]);

        $summary = [
            'iva_devengado' => ['base' => '0.00', 'tax' => '0.00'],
            'iva_soportado' => ['base' => '0.00', 'tax' => '0.00'],
            'iva_general' => ['base' => '0.00', 'tax' => '0.00'],
        ];

        foreach ($rows as $row) {
            if (! isset($summary[$row->tax_classification])) {
                continue;
            }

            $summary[$row->tax_classification]['base'] = bcadd(
                $summary[$row->tax_classification]['base'], (string) $row->taxable_base, 2
            );
            $summary[$row->tax_classification]['tax'] = bcadd(
                $summary[$row->tax_classification]['tax'], (string) $row->tax_amount, 2
            );
        }

        $summary['neto_a_pagar'] = bcsub($summary['iva_devengado']['tax'], $summary['iva_soportado']['tax'], 2);

        return $summary;
    }
}
