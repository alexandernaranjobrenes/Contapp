<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Carbon;

/** Cuántos asientos siguen en preliminar, y desde cuándo el más viejo. */
class DraftEntriesWidget extends DashboardWidget
{
    public function key(): string
    {
        return 'draft_entries';
    }

    public function title(): string
    {
        return 'Asientos preliminares';
    }

    public function description(): string
    {
        return 'Los asientos que todavía no se contabilizan: no cuentan en los saldos ni en los reportes.';
    }

    public function section(): string
    {
        return 'accounting';
    }

    public function kind(): string
    {
        return 'stat';
    }

    public function size(): string
    {
        return 'small';
    }

    public function screens(): array
    {
        return ['accounting.journal_entries'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('journal-entries.index');
    }

    public function data(DashboardContext $context): array
    {
        $drafts = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $context->company->id)
            ->where('status', 'draft');

        $count = (clone $drafts)->count();
        $oldest = $count > 0 ? (clone $drafts)->min('posting_date') : null;

        return [
            'format' => 'count',
            'value' => $count,
            'unit' => $count === 1 ? 'asiento por contabilizar' : 'asientos por contabilizar',
            'note' => $oldest
                ? 'El más viejo es del '.Carbon::parse($oldest)->format('d/m/Y').'.'
                : 'Nada pendiente: todo está contabilizado.',
        ];
    }
}
