<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollPeriodEvent;
use Illuminate\Support\Facades\DB;

/**
 * Deshacer una planilla: reabrir y anular.
 *
 * ── Por qué NO existe «editar» ni «borrar» una planilla confirmada ───────
 *
 * Una planilla confirmada ya salió del sistema: tiene un asiento contable
 * detrás, movió saldos de préstamo, acreditó vacaciones, se le pagó a la
 * gente y se le reportó a la Caja. Borrarla deja la contabilidad respaldando
 * algo que no existe; editarla deja el asiento diciendo un número y la
 * planilla otro. En cualquiera de los dos casos, un auditor no puede
 * distinguir «esto nunca pasó» de «esto lo borraron».
 *
 * Es además la regla del propio sistema (CLAUDE.md): nada contabilizado se
 * borra, se anula con asiento de reversión. Hacer una excepción justo en
 * planillas sería abrirla en el módulo que más necesita poder defenderse
 * ante el Ministerio de Trabajo o la CCSS.
 *
 * ── Las dos operaciones, y por qué son distintas ─────────────────────────
 *
 *   REABRIR   una planilla APROBADA pero no contabilizada. No salió nada:
 *             vuelve a «calculada» y se corrige. No hay asiento que revertir.
 *
 *   ANULAR    una planilla CONTABILIZADA. Hay que revertir el asiento con un
 *             contraasiento, devolver los saldos de préstamo y quitar las
 *             vacaciones acreditadas. El período vuelve a «abierta» para
 *             poder rehacerla con sus mismas fechas y su mismo número.
 *
 * Las dos exigen razón y quedan en la bitácora del período.
 *
 * ── Por qué anular devuelve el período a «abierta» ───────────────────────
 *
 * La alternativa sería dejarlo anulado para siempre y obligar a crear otro
 * período. Pero el número y las fechas son los mismos —es la quincena de
 * marzo, no otra—, y un período nuevo chocaría contra la unicidad del número
 * y contra la validación de traslape. Reabrirlo es lo que el usuario quiere
 * hacer de verdad: corregir la quincena de marzo.
 *
 * Lo que se conserva es lo que importa: el asiento original, su reversión, y
 * la bitácora que dice quién anuló y por qué. Las boletas se rehacen, porque
 * se están corrigiendo.
 */
class PayrollPeriodLifecycleService
{
    public function __construct(
        private readonly PostJournalService $postJournalService,
        private readonly CalculatePayrollService $calculator,
    ) {}

    /**
     * Devuelve una planilla aprobada a «calculada» para poder corregirla.
     */
    public function reopen(Company $company, PayrollPeriod $period, string $reason, ?int $userId = null): PayrollPeriod
    {
        if ($period->status !== 'approved') {
            throw new InvalidPayrollException(
                $period->status === 'posted' || $period->status === 'closed'
                    ? "El período «{$period->name}» ya está contabilizado: no se reabre, se anula."
                    : "El período «{$period->name}» está ".
                      mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status).
                      ': solo se reabre una planilla aprobada.'
            );
        }

        return DB::transaction(function () use ($company, $period, $reason, $userId) {
            $this->log($company, $period, 'reopened', $period->status, 'calculated', $reason, null, $userId);

            $period->update([
                'status' => 'calculated',
                // Se limpia la aprobación: la planilla corregida la tiene que
                // volver a aprobar alguien, y dejar la firma anterior diría
                // que aprobó unos números que ya no existen.
                'approved_at' => null,
                'approved_by' => null,
            ]);

            return $period->fresh();
        });
    }

    /**
     * Anula una planilla contabilizada y la devuelve a «abierta».
     */
    public function void(
        Company $company,
        PayrollPeriod $period,
        string $reason,
        ?\DateTimeInterface $postingDate = null,
        ?int $userId = null,
    ): PayrollPeriod {
        if (! in_array($period->status, ['posted', 'closed'], true)) {
            throw new InvalidPayrollException(
                "El período «{$period->name}» no está contabilizado: no hay nada que anular. ".
                ($period->status === 'approved' ? 'Usá «reabrir» para corregirlo.' : '')
            );
        }

        if ($period->journal_entry_id === null) {
            throw new InvalidPayrollException(
                "El período «{$period->name}» figura como contabilizado pero no tiene asiento asociado. ".
                'Revisalo antes de anularlo.'
            );
        }

        $original = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->findOr($period->journal_entry_id, fn () => throw new InvalidPayrollException(
                'El asiento de la planilla no existe en esta compañía.'
            ));

        return DB::transaction(function () use ($company, $period, $original, $reason, $postingDate, $userId) {
            // El contraasiento va con la fecha que se indique, o con la del
            // asiento original si no se indica ninguna: anular en el mismo
            // período contable es lo que deja los saldos como si la planilla
            // nunca se hubiera contabilizado.
            $date = $postingDate ?? $original->posting_date;

            $reversal = $this->postJournalService->reverse(
                $company,
                $original,
                $date,
                "Anulación de planilla {$period->name}",
                $userId,
            );

            // Se deshace lo que el cálculo movió fuera de la planilla: los
            // saldos de préstamo vuelven y las vacaciones acreditadas se
            // quitan. Sin esto, la planilla corregida rebajaría la cuota del
            // préstamo dos veces y acreditaría los días otra vez.
            $this->calculator->discardCalculation($period);

            $this->log($company, $period, 'voided', $period->status, 'open', $reason, $reversal->id, $userId);

            $period->update([
                'status' => 'open',
                'reversal_journal_entry_id' => $reversal->id,
                // El asiento original se conserva en la bitácora; acá se
                // suelta para que la planilla corregida pueda contabilizar el
                // suyo sin pisar el vínculo del anterior.
                'journal_entry_id' => null,
                'calculated_at' => null,
                'approved_at' => null,
                'approved_by' => null,
            ]);

            return $period->fresh();
        });
    }

    private function log(
        Company $company,
        PayrollPeriod $period,
        string $event,
        ?string $from,
        string $to,
        ?string $reason,
        ?int $journalEntryId,
        ?int $userId,
    ): void {
        PayrollPeriodEvent::create([
            'company_id' => $company->id,
            'payroll_period_id' => $period->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'journal_entry_id' => $journalEntryId,
            'created_by' => $userId,
        ]);
    }
}
