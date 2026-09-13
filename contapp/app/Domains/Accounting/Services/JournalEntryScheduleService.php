<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntrySchedule;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use Illuminate\Support\Carbon;

/**
 * "Programable": genera asientos preliminares automáticamente según una
 * frecuencia configurada, sin contabilizarlos — quedan como borrador con
 * schedule_id para que alguien los revise y decida contabilizar (regla del
 * pedido: "bajo la condición de preliminares hasta que manualmente se
 * revisen"). Recibe $company explícitamente y bypasea el CompanyScope
 * ambiental, igual que PostJournalService: processDueForAllCompanies() se
 * piensa para correr desde un comando de consola sin contexto HTTP.
 */
class JournalEntryScheduleService
{
    public function __construct(
        private readonly PostJournalService $postJournalService,
    ) {}

    /**
     * @param  JournalLineInput[]  $lines
     */
    public function create(
        Company $company,
        DocumentType $documentType,
        array $lines,
        string $frequencyType,
        int $intervalCount,
        \DateTimeInterface $startDate,
        ?\DateTimeInterface $expiresAt = null,
        ?string $description = null,
        ?int $createdBy = null,
    ): JournalEntrySchedule {
        if ($documentType->company_id !== $company->id) {
            throw new \InvalidArgumentException('El tipo de documento no pertenece a la compañía indicada.');
        }

        if (empty($lines)) {
            throw new \InvalidArgumentException('Una programación requiere al menos una línea.');
        }

        if (! in_array($frequencyType, ['days', 'months'], true)) {
            throw new \InvalidArgumentException("Frecuencia inválida: '{$frequencyType}' (debe ser 'days' o 'months').");
        }

        if ($intervalCount < 1) {
            throw new \InvalidArgumentException('El intervalo debe ser mayor a cero.');
        }

        if ($expiresAt !== null && $expiresAt->format('Y-m-d') < $startDate->format('Y-m-d')) {
            throw new \InvalidArgumentException('La fecha de vencimiento no puede ser anterior a la fecha de inicio.');
        }

        return JournalEntrySchedule::create([
            'company_id' => $company->id,
            'document_type_id' => $documentType->id,
            'description' => $description,
            // Plantilla deliberadamente reducida frente a una línea normal:
            // clave electrónica, vencimiento de línea, IVA y aplicación a
            // partida son datos de UN documento puntual, no de una plantilla
            // que se repite — quien revise cada borrador generado los
            // completa a mano si de verdad corresponden (docs/decisiones.md).
            'lines' => array_map(fn (JournalLineInput $l) => [
                'account_id' => $l->accountId,
                'currency_id' => $l->currencyId,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'description' => $l->description,
                'business_partner_id' => $l->businessPartnerId,
                'cost_allocation_rule_id' => $l->costAllocationRuleId,
            ], $lines),
            'frequency_type' => $frequencyType,
            'interval_count' => $intervalCount,
            'next_run_date' => $startDate->format('Y-m-d'),
            'expires_at' => $expiresAt?->format('Y-m-d'),
            'status' => 'active',
            'created_by' => $createdBy,
        ]);
    }

    public function cancel(JournalEntrySchedule $schedule): void
    {
        if ($schedule->status !== 'active') {
            throw new \InvalidArgumentException('Solo se puede cancelar una programación activa.');
        }

        $schedule->update(['status' => 'cancelled']);
    }

    /**
     * @return JournalEntry[]
     */
    public function processDue(Company $company, ?\DateTimeInterface $today = null): array
    {
        $today ??= now();

        $due = JournalEntrySchedule::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereDate('next_run_date', '<=', $today->format('Y-m-d'))
            ->get();

        $generated = [];

        foreach ($due as $schedule) {
            $documentType = DocumentType::withoutGlobalScope(CompanyScope::class)->findOrFail($schedule->document_type_id);

            $lines = array_map(fn (array $l) => new JournalLineInput(
                accountId: $l['account_id'],
                currencyId: $l['currency_id'],
                debit: $l['debit'],
                credit: $l['credit'],
                description: $l['description'] ?? null,
                businessPartnerId: $l['business_partner_id'] ?? null,
                costAllocationRuleId: $l['cost_allocation_rule_id'] ?? null,
                allowZeroAmount: true,
            ), $schedule->lines);

            $entry = $this->postJournalService->saveDraft(
                $company,
                $documentType,
                $today,
                $today,
                $lines,
                $schedule->description,
                $schedule->created_by,
            );

            $entry->update(['schedule_id' => $schedule->id]);
            $generated[] = $entry;

            // addMonthsNoOverflow, no addMonths: una programación anclada al
            // día 31 no debe "correrse" a marzo 3 cuando pasa por febrero —
            // se queda en el último día de ese mes (28/29 de febrero) y
            // retoma el día 31 en el siguiente mes que sí lo tenga.
            $nextRun = $schedule->frequency_type === 'days'
                ? Carbon::parse($schedule->next_run_date)->addDays($schedule->interval_count)
                : Carbon::parse($schedule->next_run_date)->addMonthsNoOverflow($schedule->interval_count);

            $schedule->next_run_date = $nextRun;

            if ($schedule->expires_at !== null && $nextRun->gt($schedule->expires_at)) {
                $schedule->status = 'expired';
            }

            $schedule->save();
        }

        return $generated;
    }

    /**
     * @return array<int, JournalEntry[]>
     */
    public function processDueForAllCompanies(?\DateTimeInterface $today = null): array
    {
        $results = [];

        Company::withoutGlobalScope(CompanyScope::class)
            ->where('status', 'active')
            ->each(function (Company $company) use ($today, &$results) {
                $results[$company->id] = $this->processDue($company, $today);
            });

        return $results;
    }
}
