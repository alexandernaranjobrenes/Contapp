<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\LaborSettlement;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\PersonnelAction;
use App\Domains\Payroll\Models\VacationMovement;
use Illuminate\Support\Facades\DB;

/**
 * Contabiliza una liquidación laboral.
 *
 * ── Por qué el asiento NO es todo gasto ──────────────────────────────────
 *
 * Es la diferencia más importante con la planilla, y la que más se hace mal.
 *
 * El aguinaldo, las vacaciones y la cesantía se fueron PROVISIONANDO mes a
 * mes: cada planilla ya reconoció el gasto y dejó un pasivo acumulado.
 * Cuando llega la liquidación, pagarlos no es un gasto nuevo — es cancelar
 * ese pasivo. Cargarlos otra vez a gasto registra el mismo costo dos veces y
 * deja la provisión inflada para siempre, creciendo año con año hasta que
 * alguien la audita.
 *
 *   Debe     Provisión de aguinaldo        (pasivo que se cancela)
 *   Debe     Provisión de vacaciones       (pasivo que se cancela)
 *   Debe     Provisión de cesantía         (pasivo que se cancela)
 *   Debe     Gasto de preaviso             (este SÍ es gasto del período)
 *   Haber    CCSS por pagar                cargas sobre la parte salarial
 *   Haber    Impuesto por pagar            retención de renta
 *   Haber    Liquidación por pagar         el neto al trabajador
 *
 * El preaviso es la excepción porque su provisión está en 0%: solo existe
 * cuando hay despido sin aviso previo, así que no se puede provisionar mes a
 * mes y es gasto del período en que ocurre.
 *
 * Cada renglón lleva la cuenta que se le resolvió al calcular, congelada. Si
 * la provisión no tiene cuenta configurada, el cálculo ya cayó a la de gasto
 * de salarios: es peor contabilidad, pero es honesta y visible.
 *
 * ── El pago es otro asiento ──────────────────────────────────────────────
 *
 * Igual que en la planilla: acá no se toca el banco. El neto queda como
 * pasivo y el pago lo cancela cuando el dinero de verdad sale.
 *
 * ── Anular no borra ──────────────────────────────────────────────────────
 *
 * Una liquidación contabilizada se anula con un asiento de reversión, nunca
 * se borra: es un documento que el trabajador firmó y que puede reclamarse
 * durante un año. Anularla además devuelve al trabajador el saldo de
 * vacaciones que se le liquidó, porque si no, reliquidarlo lo dejaría en
 * cero días.
 */
class PostSettlementService
{
    public function __construct(private readonly PostJournalService $postJournalService) {}

    public function post(
        Company $company,
        LaborSettlement $settlement,
        ?\DateTimeInterface $postingDate = null,
        ?int $createdBy = null,
    ): LaborSettlement {
        if (! in_array($settlement->status, ['draft', 'approved'], true)) {
            throw new InvalidPayrollException(
                'La liquidación está '.mb_strtolower(LaborSettlement::STATUSES[$settlement->status] ?? $settlement->status).
                ': solo se contabiliza una en borrador o aprobada.'
            );
        }

        $settlement->loadMissing(['lines', 'employee']);

        if ($settlement->lines->isEmpty()) {
            throw new InvalidPayrollException('La liquidación no tiene renglones que contabilizar.');
        }

        $settings = PayrollSetting::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->first();

        if ($settings === null || $settings->net_payable_account_id === null) {
            throw new InvalidPayrollException(
                'Falta la cuenta de planilla por pagar en la configuración: sin ella la liquidación no se puede contabilizar.'
            );
        }

        $documentType = $this->documentType($company, $settlement, $settings);

        return DB::transaction(function () use ($company, $settlement, $settings, $documentType, $postingDate, $createdBy) {
            $employee = $settlement->employee;
            $label = $employee?->fullName() ?? "Empleado #{$settlement->employee_id}";
            $date = $postingDate ?? $settlement->termination_date;

            $lines = $this->linesFor($company, $settlement, $settings, $label);

            $journalEntry = $this->postJournalService->post(
                company: $company,
                documentType: $documentType,
                documentDate: $date,
                postingDate: $date,
                lines: $lines,
                description: "Liquidación laboral — {$label}",
                createdBy: $createdBy,
            );

            // El saldo de vacaciones liquidado se descarga del control: los
            // días ya se pagaron, y dejarlos en el saldo haría que una
            // segunda liquidación los volviera a pagar.
            $this->settleVacationBalance($company, $settlement, $createdBy);

            // La salida se refleja en la ficha, que es donde la gente la
            // busca. La fecha llena inactiva al trabajador: fue explícito en
            // los requisitos y evita que siga apareciendo en la planilla del
            // mes siguiente.
            $employee?->update([
                'termination_date' => $settlement->termination_date->format('Y-m-d'),
                'termination_reason' => $settlement->reason_detail
                    ?? (LaborSettlement::REASONS[$settlement->reason] ?? $settlement->reason),
                'status' => 'terminated',
            ]);

            $this->recordAction($company, $settlement, $createdBy);

            $settlement->update([
                'status' => 'posted',
                'journal_entry_id' => $journalEntry->id,
                'document_type_id' => $documentType->id,
            ]);

            return $settlement->fresh(['lines']);
        });
    }

    /**
     * Anula una liquidación contabilizada con un asiento de reversión.
     *
     * Devuelve al trabajador los días de vacaciones que se le liquidaron y lo
     * reactiva: si se anuló la liquidación es porque la relación no terminó
     * como se registró, y dejarlo inactivo lo excluiría de la planilla.
     */
    public function void(
        Company $company,
        LaborSettlement $settlement,
        string $reason,
        ?\DateTimeInterface $postingDate = null,
        ?int $createdBy = null,
    ): LaborSettlement {
        if ($settlement->status !== 'posted') {
            throw new InvalidPayrollException(
                'Solo se anula una liquidación contabilizada. Una en borrador se puede eliminar.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidPayrollException('Hay que indicar el motivo de la anulación.');
        }

        $settlement->loadMissing(['journalEntry', 'employee']);

        if ($settlement->journalEntry === null) {
            throw new InvalidPayrollException(
                'La liquidación está contabilizada pero no tiene asiento: hay que revisarla antes de anularla.'
            );
        }

        return DB::transaction(function () use ($company, $settlement, $reason, $postingDate, $createdBy) {
            $label = $settlement->employee?->fullName() ?? "Empleado #{$settlement->employee_id}";

            $this->postJournalService->reverse(
                company: $company,
                original: $settlement->journalEntry,
                postingDate: $postingDate ?? now(),
                description: "Anulación de liquidación — {$label}: {$reason}",
                createdBy: $createdBy,
            );

            // Se devuelven los días liquidados: son los del movimiento de
            // liquidación, no los que el trabajador tenga hoy.
            VacationMovement::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->where('labor_settlement_id', $settlement->id)
                ->delete();

            $settlement->employee?->update([
                'termination_date' => null,
                'status' => 'active',
            ]);

            $settlement->update([
                'status' => 'voided',
                'notes' => trim(($settlement->notes ? $settlement->notes."\n" : '').
                    'Anulada el '.now()->format('d/m/Y').': '.$reason),
            ]);

            $this->recordAction($company, $settlement, $createdBy, "Liquidación anulada: {$reason}");

            return $settlement->fresh(['lines']);
        });
    }

    /**
     * Las líneas del asiento.
     *
     * @return JournalLineInput[]
     */
    private function linesFor(
        Company $company,
        LaborSettlement $settlement,
        PayrollSetting $settings,
        string $label,
    ): array {
        $currencyId = $company->local_currency_id;
        $costCenterId = $settlement->cost_center_id ?? $settlement->employee?->cost_center_id;
        $lines = [];

        // ── Débitos: cada extremo contra su cuenta ──────────────────────
        foreach ($settlement->lines as $line) {
            if ($line->kind === 'deduction') {
                continue;
            }

            if ($line->account_id === null) {
                throw new InvalidPayrollException(
                    "El renglón {$line->code} ({$line->name}) no tiene cuenta contable. ".
                    'Hay que asignarla en Configuración → Determinación de cuentas antes de contabilizar.'
                );
            }

            $lines[] = new JournalLineInput(
                accountId: $line->account_id,
                currencyId: $currencyId,
                debit: $line->amount,
                credit: 0,
                description: "{$line->name} — {$label}",
                // Solo el gasto lleva centro de costo; cancelar un pasivo no
                // es costo de ningún departamento.
                costCenterId: $line->kind === 'notice' ? $costCenterId : null,
            );
        }

        // ── Haberes: cargas obreras ─────────────────────────────────────
        if (bccomp((string) $settlement->total_ccss, '0.00', 2) > 0) {
            $lines[] = new JournalLineInput(
                accountId: $this->ccssPayableAccount($company, $settlement, $settings),
                currencyId: $currencyId,
                debit: 0,
                credit: $settlement->total_ccss,
                description: "Cargas sociales retenidas — {$label}",
            );
        }

        // ── Haberes: impuesto retenido ──────────────────────────────────
        if (bccomp((string) $settlement->total_income_tax, '0.00', 2) > 0) {
            $lines[] = new JournalLineInput(
                accountId: $settings->income_tax_payable_account_id ?? $settings->net_payable_account_id,
                currencyId: $currencyId,
                debit: 0,
                credit: $settlement->total_income_tax,
                description: "Impuesto al salario retenido — {$label}",
            );
        }

        // ── Haberes: las deducciones, cada una a su cuenta ──────────────
        foreach ($settlement->lines->where('kind', 'deduction') as $line) {
            $lines[] = new JournalLineInput(
                accountId: $line->account_id ?? $settings->net_payable_account_id,
                currencyId: $currencyId,
                debit: 0,
                credit: $line->amount,
                description: "{$line->name} — {$label}",
            );
        }

        // ── Haberes: el neto, como pasivo con el trabajador ─────────────
        if (bccomp((string) $settlement->total_net, '0.00', 2) > 0) {
            $lines[] = new JournalLineInput(
                accountId: $settings->net_payable_account_id,
                currencyId: $currencyId,
                debit: 0,
                credit: $settlement->total_net,
                description: "Liquidación neta a pagar — {$label}",
            );
        }

        return $lines;
    }

    /**
     * La cuenta de CCSS por pagar: la de la primera carga obrera vigente.
     *
     * Todas las cargas obreras se enteran a la misma planilla de la Caja y en
     * la práctica comparten cuenta; tomar la primera con cuenta configurada
     * evita pedir un parámetro más para lo mismo.
     */
    private function ccssPayableAccount(
        Company $company,
        LaborSettlement $settlement,
        PayrollSetting $settings,
    ): int {
        $account = PayrollContribution::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('payer', 'employee')
            ->effectiveOn($settlement->termination_date->format('Y-m-d'))
            ->whereNotNull('liability_account_id')
            ->orderBy('code')
            ->value('liability_account_id');

        return $account ?? $settings->net_payable_account_id;
    }

    /**
     * Descarga del control de vacaciones los días que se liquidaron.
     *
     * Queda como un movimiento más, con su tipo propio, para que el historial
     * del trabajador muestre por qué su saldo bajó a cero.
     */
    private function settleVacationBalance(Company $company, LaborSettlement $settlement, ?int $createdBy): void
    {
        $line = $settlement->lines->firstWhere('kind', 'vacation');

        if ($line === null || bccomp((string) $line->days, '0.0000', 4) <= 0) {
            return;
        }

        VacationMovement::create([
            'company_id' => $company->id,
            'employee_id' => $settlement->employee_id,
            'labor_settlement_id' => $settlement->id,
            'type' => 'settlement',
            'movement_date' => $settlement->termination_date->format('Y-m-d'),
            // En negativo: el saldo es la SUMA de los movimientos.
            'days' => bcmul((string) $line->days, '-1', 4),
            'notes' => 'Liquidación laboral: '.($line->detail ?? 'días pagados'),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Deja la liquidación en la bitácora de acciones de personal, que es
     * donde se lee la historia del trabajador.
     */
    private function recordAction(
        Company $company,
        LaborSettlement $settlement,
        ?int $createdBy,
        ?string $detail = null,
    ): void {
        PersonnelAction::create([
            'company_id' => $company->id,
            'employee_id' => $settlement->employee_id,
            'action_type' => 'termination',
            'effective_date' => $settlement->termination_date->format('Y-m-d'),
            'reason' => $detail ?? (LaborSettlement::REASONS[$settlement->reason] ?? $settlement->reason).
                ' — neto liquidado ₡'.$settlement->total_net,
            // Aplicada, no borrador: la ficha ya quedó modificada por este
            // mismo proceso, y una acción "pendiente" de algo que ya ocurrió
            // deja la bandeja de aprobaciones mintiendo.
            'status' => 'applied',
            'applied_at' => now(),
            'requested_by' => $createdBy,
            'notes' => $settlement->reason_detail,
        ]);
    }

    private function documentType(
        Company $company,
        LaborSettlement $settlement,
        PayrollSetting $settings,
    ): DocumentType {
        $id = $settlement->document_type_id ?? $settings->document_type_id;

        if ($id === null) {
            throw new InvalidPayrollException(
                'La configuración de planilla no tiene un tipo de documento para el asiento.'
            );
        }

        return DocumentType::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->findOr($id, fn () => throw new InvalidPayrollException(
                'El tipo de documento configurado para la planilla no existe en esta compañía.'
            ));
    }
}
