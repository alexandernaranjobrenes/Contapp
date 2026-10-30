<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use App\Domains\Core\Scopes\CompanyScope;

/** Los últimos asientos, por fecha de contabilización: lo que traía el Panel de antes. */
class RecentEntriesWidget extends DashboardWidget
{
    private const STATUSES = [
        'draft' => ['label' => 'Preliminar', 'badge' => 'badge-warning'],
        'posted' => ['label' => 'Contabilizado', 'badge' => 'badge-success'],
        'voided' => ['label' => 'Anulado', 'badge' => 'badge-neutral'],
    ];

    public function key(): string
    {
        return 'recent_entries';
    }

    public function title(): string
    {
        return 'Últimos asientos';
    }

    public function description(): string
    {
        return 'Los seis asientos más recientes, con su estado. Cada uno abre su documento.';
    }

    public function section(): string
    {
        return 'accounting';
    }

    public function kind(): string
    {
        return 'list';
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
        $entries = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $context->company->id)
            ->with('documentType:id,code')
            ->latest('posting_date')
            ->latest('id')
            ->take(6)
            ->get(['id', 'document_type_id', 'document_number', 'posting_date', 'description', 'status']);

        return [
            'rows' => $entries->map(fn (JournalEntry $entry) => [
                'title' => $entry->document_number
                    ? "{$entry->documentType?->code}-{$entry->document_number}"
                    : trim(($entry->documentType?->code ?? '').' — sin número aún'),
                'subtitle' => $entry->description,
                'meta' => $entry->posting_date?->format('d/m/Y'),
                'badge' => self::STATUSES[$entry->status] ?? null,
                'href' => route('journal-entries.show', $entry->id),
            ])->all(),
            'empty' => 'Todavía no hay asientos.',
        ];
    }
}
