<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\Core\Support\CurrentCompany;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(CurrentCompany $currentCompany): Response
    {
        if (! $currentCompany->isSet()) {
            return Inertia::render('Dashboard', [
                'noCompany' => true,
            ]);
        }

        return Inertia::render('Dashboard', [
            'noCompany' => false,
            'stats' => [
                'accounts' => ChartOfAccount::count(),
                'journalEntries' => JournalEntry::count(),
                'openItems' => BpOpenItem::whereHas(
                    'businessPartner',
                    fn ($q) => $q->where('company_id', $currentCompany->id())
                )->where('status', '!=', 'closed')->count(),
            ],
            'recentEntries' => JournalEntry::with('documentType:id,code,name')
                ->latest('posting_date')
                ->latest('id')
                ->take(8)
                ->get(['id', 'document_type_id', 'document_number', 'posting_date', 'description', 'status']),
        ]);
    }
}
