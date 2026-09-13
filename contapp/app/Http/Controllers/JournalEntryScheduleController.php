<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntrySchedule;
use App\Domains\Accounting\Services\JournalEntryScheduleService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class JournalEntryScheduleController extends Controller
{
    public function index(): Response
    {
        $schedules = JournalEntrySchedule::with('documentType:id,code,name')
            ->withCount('generatedEntries')
            ->latest('id')
            ->get(['id', 'document_type_id', 'description', 'frequency_type', 'interval_count', 'next_run_date', 'expires_at', 'status']);

        // "Registros pendientes programados": los borradores que una
        // programación ya generó y todavía nadie revisó ni contabilizó —
        // condición de preliminares del pedido, hasta que se editen/contabilicen
        // a mano desde la misma pantalla de siempre (journal-entries.edit).
        $pendingEntries = JournalEntry::whereNotNull('schedule_id')
            ->where('status', 'draft')
            ->with(['documentType:id,code,name', 'schedule:id,description'])
            ->latest('posting_date')
            ->get(['id', 'document_type_id', 'schedule_id', 'posting_date', 'description']);

        return Inertia::render('JournalEntrySchedules/Index', [
            'schedules' => $schedules,
            'pendingEntries' => $pendingEntries,
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany, JournalEntryScheduleService $service): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate([
            'document_type_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'frequency_type' => ['required', 'in:days,months'],
            'interval_count' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:start_date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.currency_id' => ['required', 'integer'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.cost_allocation_rule_id' => ['nullable', 'integer', Rule::exists('cost_allocation_rules', 'id')->where('company_id', $companyId)],
            'lines.*.business_partner_id' => ['nullable', 'integer', Rule::exists('business_partners', 'id')->where('company_id', $companyId)],
        ]);

        $company = Company::findOrFail($companyId);
        $documentType = DocumentType::findOrFail($validated['document_type_id']);

        $lines = array_map(fn (array $line) => new JournalLineInput(
            accountId: (int) $line['account_id'],
            currencyId: (int) $line['currency_id'],
            debit: $line['debit'],
            credit: $line['credit'],
            description: $line['description'] ?? null,
            businessPartnerId: isset($line['business_partner_id']) ? (int) $line['business_partner_id'] : null,
            costAllocationRuleId: isset($line['cost_allocation_rule_id']) ? (int) $line['cost_allocation_rule_id'] : null,
            allowZeroAmount: true,
        ), $validated['lines']);

        try {
            $service->create(
                $company,
                $documentType,
                $lines,
                $validated['frequency_type'],
                (int) $validated['interval_count'],
                new \DateTime($validated['start_date']),
                isset($validated['expires_at']) ? new \DateTime($validated['expires_at']) : null,
                $validated['description'] ?? null,
                $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['schedule' => $e->getMessage()])->withInput();
        }

        return redirect()->route('journal-entry-schedules.index')
            ->with('success', 'Programación creada. Va a generar un asiento preliminar cada vez que llegue la fecha, hasta el vencimiento indicado.');
    }

    public function cancel(int $journalEntrySchedule, JournalEntryScheduleService $service): RedirectResponse
    {
        $schedule = JournalEntrySchedule::findOrFail($journalEntrySchedule);

        try {
            $service->cancel($schedule);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['schedule' => $e->getMessage()]);
        }

        return back()->with('success', 'Programación cancelada. No va a generar más asientos.');
    }

    /**
     * Disparo manual — mismo criterio que ExchangeRateController::sync():
     * el comando de consola (contapp:process-journal-schedules) requiere un
     * cron real corriendo `schedule:run`, que este proyecto no tiene
     * configurado todavía, así que esta pantalla ofrece un botón para no
     * depender de eso mientras tanto.
     */
    public function processNow(CurrentCompany $currentCompany, JournalEntryScheduleService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        $generated = $service->processDue($company);

        return back()->with('success', count($generated).' asiento(s) preliminar(es) generado(s) por programaciones vencidas.');
    }
}
