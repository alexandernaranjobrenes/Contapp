<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\LaborSettlement;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;
use Carbon\Carbon;

/**
 * El pasivo laboral acumulado: cuánto se ha provisionado por cada trabajador y
 * cuánto de eso ya se pagó.
 *
 * ── Es el reporte que cuadra la provisión contra el mayor ────────────────
 *
 * Cada planilla acumula provisión de aguinaldo, vacaciones y cesantía, y cada
 * liquidación cancela parte de ese pasivo. La cuenta contable de provisión
 * debería tener, en cualquier momento, la diferencia.
 *
 * Cuando no la tiene, la causa está casi siempre en uno de tres lugares: una
 * liquidación contabilizada contra gasto en vez de contra la provisión, un
 * período anulado cuya reversión no se hizo, o un asiento manual que alguien
 * metió directo a la cuenta. Este reporte da el número contra el cual comparar;
 * sin él, la provisión crece año con año y nadie sabe si está bien.
 *
 * ── Por qué el aguinaldo se muestra aparte ──────────────────────────────
 *
 * Porque es el único que se paga entero en una fecha conocida —diciembre— y por
 * eso es el que se usa para planear el flujo de caja. La cesantía puede no
 * pagarse nunca; el aguinaldo se paga siempre.
 */
class ProvisionLiabilityReport implements PayrollReport
{
    public function code(): string
    {
        return 'provisions';
    }

    public function label(): string
    {
        return 'Pasivo laboral acumulado';
    }

    public function description(): string
    {
        return 'Provisión de aguinaldo, vacaciones y cesantía acumulada por trabajador, menos lo ya pagado.';
    }

    public function decision(): string
    {
        return 'Cuánto debe la empresa en prestaciones y si la cuenta de provisión está cuadrada.';
    }

    public function group(): string
    {
        return 'Costos y pasivo laboral';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('as_of', 'Acumulado hasta', ReportFilter::DATE, default: 'today',
                hint: 'Por fecha de fin de período. Para cuadrar contra el mayor, usá la misma fecha de corte.'),
            new ReportFilter('from', 'Acumulado desde', ReportFilter::DATE,
                hint: 'En blanco acumula desde la primera planilla: es lo correcto para el pasivo total.'),
            new ReportFilter('cost_center_id', 'Centro de costo', ReportFilter::SELECT, optionSource: 'cost_centers'),
            new ReportFilter('active_only', 'Solo trabajadores activos', ReportFilter::BOOLEAN,
                hint: 'Cuidado: el pasivo de alguien que salió y no se liquidó sigue existiendo.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $asOf = $filters['as_of'] ?? now()->format('Y-m-d');

        $columns = [
            new ReportColumn('code', 'Código'),
            new ReportColumn('employee', 'Trabajador'),
            new ReportColumn('cost_center', 'C. costo'),
            new ReportColumn('status', 'Estado'),
            new ReportColumn('periods', 'Planillas', ReportColumn::NUMBER, totalizable: true),
            new ReportColumn('christmas_bonus', 'Provisión aguinaldo', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('vacation', 'Provisión vacaciones', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('severance', 'Provisión cesantía', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('other', 'Otras provisiones', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('accrued', 'Total provisionado', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('settled', 'Pagado en liquidación', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('outstanding', 'Pasivo pendiente', ReportColumn::MONEY, totalizable: true),
        ];

        $periodQuery = PayrollPeriod::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', ['calculated', 'approved', 'posted', 'closed'])
            ->whereDate('end_date', '<=', $asOf);

        if (($filters['from'] ?? null) !== null) {
            $periodQuery->whereDate('end_date', '>=', $filters['from']);
        }

        $periodIds = $periodQuery->pluck('id');

        $notes = ['Acumulado hasta el '.Carbon::parse($asOf)->format('d/m/Y').
            ' sobre '.$periodIds->count().' planilla(s) calculadas.'];

        if ($periodIds->isEmpty()) {
            $notes[] = 'No hay planillas calculadas en ese rango.';

            return new ReportResult($columns, [], notes: $notes);
        }

        $entries = PayrollEntry::with([
            'employee:id,code,first_name,last_name1,last_name2,status,cost_center_id',
            'employee.costCenter:id,code',
            'lines',
        ])
            ->whereIn('payroll_period_id', $periodIds)
            ->when(($filters['cost_center_id'] ?? null) !== null,
                fn ($q) => $q->where('cost_center_id', $filters['cost_center_id']))
            ->get()
            ->groupBy('employee_id');

        // Lo que ya se pagó por liquidación, para restarlo: es la otra mitad
        // del movimiento del pasivo.
        $settled = LaborSettlement::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('status', 'posted')
            ->whereDate('termination_date', '<=', $asOf)
            ->with('lines')
            ->get()
            ->groupBy('employee_id');

        $rows = [];

        foreach ($entries as $employeeId => $employeeEntries) {
            $employee = $employeeEntries->first()->employee;

            if (($filters['active_only'] ?? false) && $employee?->status !== 'active') {
                continue;
            }

            $byCode = ['aguinaldo' => '0.00', 'vacaciones' => '0.00', 'cesantia' => '0.00', 'otras' => '0.00'];

            foreach ($employeeEntries as $entry) {
                foreach ($entry->lines->where('kind', 'provision') as $line) {
                    $bucket = array_key_exists($line->code, $byCode) ? $line->code : 'otras';
                    $byCode[$bucket] = bcadd($byCode[$bucket], (string) $line->amount, 2);
                }
            }

            $accrued = array_reduce($byCode, fn (string $c, string $v) => bcadd($c, $v, 2), '0.00');

            $paid = ($settled[$employeeId] ?? collect())
                ->flatMap(fn (LaborSettlement $s) => $s->lines
                    ->whereIn('kind', ['christmas_bonus', 'vacation', 'severance']))
                ->reduce(fn (string $c, $l) => bcadd($c, (string) $l->amount, 2), '0.00');

            $rows[] = [
                'code' => $employee?->code,
                'employee' => $employee?->fullName(),
                'cost_center' => $employee?->costCenter?->code,
                'status' => $employee?->status,
                'periods' => $employeeEntries->count(),
                'christmas_bonus' => (float) $byCode['aguinaldo'],
                'vacation' => (float) $byCode['vacaciones'],
                'severance' => (float) $byCode['cesantia'],
                'other' => (float) $byCode['otras'],
                'accrued' => (float) $accrued,
                'settled' => (float) $paid,
                'outstanding' => (float) bcsub($accrued, $paid, 2),
            ];
        }

        usort($rows, fn (array $a, array $b) => ($a['code'] ?? '') <=> ($b['code'] ?? ''));

        $notes[] = 'El «pasivo pendiente» es lo provisionado menos lo pagado en liquidaciones contabilizadas: '.
            'es el número contra el cual debería cuadrar la cuenta contable de provisión.';
        $notes[] = 'El aguinaldo va aparte porque es el único que se paga entero en una fecha conocida: es el '.
            'que sirve para planear el flujo de diciembre.';

        if ($filters['active_only'] ?? false) {
            $notes[] = 'Solo activos: el pasivo de alguien que salió y todavía no se liquidó queda fuera de '.
                'este total, aunque siga existiendo.';
        }

        if (($filters['from'] ?? null) !== null) {
            $notes[] = 'Hay un «acumulado desde», así que este NO es el pasivo total: le faltan las planillas '.
                'anteriores a esa fecha.';
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }
}
