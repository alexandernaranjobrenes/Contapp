<?php

namespace App\Domains\Accounting\Reports;

/**
 * El índice de reportes tabulares de contabilidad.
 *
 * En código y no en tabla, igual que los registros de inventario y planillas:
 * un reporte es una clase, y un registro en base de datos que apunte a una
 * clase que no existe es un error que nadie ve hasta que alguien lo abre.
 */
class AccountingReportRegistry
{
    /** @var array<string, class-string<AccountingReport>> */
    private const REPORTS = [
        'general-ledger' => GeneralLedgerReport::class,
        'journal-book' => JournalBookReport::class,
    ];

    /** @var string[] */
    public const GROUP_ORDER = ['Libros legales'];

    /** @return AccountingReport[] */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::REPORTS);
    }

    public function find(string $code): ?AccountingReport
    {
        $class = self::REPORTS[$code] ?? null;

        return $class === null ? null : app($class);
    }

    public function has(string $code): bool
    {
        return isset(self::REPORTS[$code]);
    }

    /** @return array<string, AccountingReport[]> */
    public function grouped(): array
    {
        $grouped = [];

        foreach (self::GROUP_ORDER as $group) {
            $grouped[$group] = [];
        }

        foreach ($this->all() as $report) {
            $grouped[$report->group()][] = $report;
        }

        return array_filter($grouped, fn (array $reports) => $reports !== []);
    }
}
