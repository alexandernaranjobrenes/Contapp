<?php

namespace App\Domains\Payroll\Reports\Concerns;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Reporting\Reports\ReportFilter;

/**
 * El filtro de período, que comparten casi todos los reportes de planilla.
 *
 * ── Por qué el período por defecto es el ÚLTIMO CALCULADO ────────────────
 *
 * Y no el último creado. Un período recién abierto no tiene boletas, así que
 * si fuera el default, el reporte abriría vacío y quien lo abre concluiría
 * que el reporte no sirve —no que escogió el período equivocado—. El último
 * calculado es el que tiene datos y es, casi siempre, el que se quiere ver.
 *
 * ── Por qué los períodos anulados no aparecen ────────────────────────────
 *
 * Un período vuelto a abrir tras anular su asiento sigue existiendo, pero sus
 * boletas se borraron al descartar el cálculo. Ofrecerlo en el selector es
 * ofrecer un reporte en blanco.
 */
trait ResolvesPayrollPeriod
{
    /** Los estados en que un período ya tiene boletas que reportar. */
    protected const CALCULATED_STATUSES = ['calculated', 'approved', 'posted', 'closed'];

    protected function periodFilter(string $hint = 'El período del que se saca el reporte.'): ReportFilter
    {
        return new ReportFilter(
            'payroll_period_id',
            'Período',
            ReportFilter::SELECT,
            default: 'last_calculated_period',
            optionSource: 'payroll_periods',
            hint: $hint,
        );
    }

    protected function resolvePeriod(Company $company, array $filters): ?PayrollPeriod
    {
        $id = $filters['payroll_period_id'] ?? null;

        $query = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id);

        if ($id !== null && $id !== '') {
            return $query->find($id);
        }

        return $query->whereIn('status', self::CALCULATED_STATUSES)
            ->orderByDesc('end_date')->orderByDesc('id')
            ->first();
    }

    /**
     * La nota que explica de cuál período se está viendo, con su estado. Sin
     * esto, un PDF archivado no dice si la planilla que muestra era la
     * definitiva o una calculada a medias.
     */
    protected function periodNote(?PayrollPeriod $period): array
    {
        if ($period === null) {
            return ['No hay ningún período calculado todavía: no hay nada que reportar.'];
        }

        return [sprintf(
            'Período %s — del %s al %s, pago %s. Estado: %s.',
            $period->name,
            $period->start_date->format('d/m/Y'),
            $period->end_date->format('d/m/Y'),
            $period->payment_date->format('d/m/Y'),
            mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status),
        )];
    }
}
