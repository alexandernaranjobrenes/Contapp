<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollEntryLine;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use Illuminate\Support\Facades\DB;

/**
 * Contabiliza una planilla.
 *
 * ── El asiento de una planilla tiene tres bloques, no uno ────────────────
 *
 * Es el punto donde más se simplifica de más. La tentación es un asiento de
 * dos líneas —gasto de salarios contra banco— y eso es incorrecto de tres
 * maneras a la vez.
 *
 *   BLOQUE 1 · El salario bruto
 *       Debe     Gasto de salarios          el BRUTO, no el neto
 *       Haber    CCSS por pagar             las cargas obreras retenidas
 *       Haber    Impuesto por pagar         la retención de renta
 *       Haber    Préstamos / adelantos      los rebajos del trabajador
 *       Haber    Planilla por pagar         el NETO
 *
 *   El gasto es el bruto porque eso es lo que le costó al patrono el
 *   trabajo. Las retenciones no son gasto de la empresa: son dinero del
 *   trabajador que la empresa apenas custodia hasta enterarlo. Registrar
 *   solo el neto como gasto subestima el gasto y esconde el pasivo.
 *
 *   BLOQUE 2 · Las cargas patronales
 *       Debe     Gasto de cargas sociales   lo que el patrono paga ENCIMA
 *       Haber    CCSS por pagar             contra el mismo pasivo
 *
 *   Esto no toca al trabajador y por eso se olvida. Es costo real y es
 *   grande: omitirlo hace que un puesto parezca costar bastante menos de
 *   lo que cuesta, y cotizar proyectos con ese número da pérdidas.
 *
 *   BLOQUE 3 · Las provisiones
 *       Debe     Gasto de aguinaldo/vac.    lo devengado del período
 *       Haber    Provisión por pagar        el pasivo que se acumula
 *
 *   El aguinaldo se paga en diciembre pero se GANA todo el año. Cargarlo
 *   entero a diciembre arruina la comparación entre meses y esconde un
 *   pasivo que ya existe. Lo mismo con vacaciones y cesantía.
 *
 * ── El pago es OTRO asiento ──────────────────────────────────────────────
 *
 * Acá no se toca el banco. Este asiento deja el neto en "planilla por
 * pagar"; el pago lo cancela contra el banco cuando de verdad sale el
 * dinero. Mezclarlos impide conciliar y rompe el corte cuando la planilla
 * se contabiliza un día y se paga otro — que es lo normal.
 *
 * ── El centro de costo viaja con cada trabajador ─────────────────────────
 *
 * Cada línea de gasto lleva el centro congelado en su boleta, no el de la
 * ficha de hoy. Así el costo por departamento se sostiene aunque la gente
 * se traslade.
 */
class PostPayrollService
{
    public function __construct(private readonly PostJournalService $postJournalService) {}

    public function post(
        Company $company,
        PayrollPeriod $period,
        ?\DateTimeInterface $postingDate = null,
        ?int $createdBy = null,
    ): PayrollPeriod {
        if (! in_array($period->status, ['calculated', 'approved'], true)) {
            throw new InvalidPayrollException(
                "El período {$period->name} está ".mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status).
                ': solo se contabiliza una planilla calculada o aprobada.'
            );
        }

        $settings = PayrollSetting::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->first();

        if ($settings === null
            || $settings->salary_expense_account_id === null
            || $settings->net_payable_account_id === null) {
            throw new InvalidPayrollException(
                'Faltan cuentas en la configuración de planilla: se requieren al menos la cuenta de gasto de salarios y la de planilla por pagar.'
            );
        }

        $documentType = $this->documentType($company, $period, $settings);

        return DB::transaction(function () use ($company, $period, $settings, $documentType, $postingDate, $createdBy) {
            $entries = PayrollEntry::where('payroll_period_id', $period->id)
                ->with('lines')
                ->get();

            if ($entries->isEmpty()) {
                throw new InvalidPayrollException("El período {$period->name} no tiene boletas que contabilizar.");
            }

            $employees = Employee::withoutGlobalScope(CompanyScope::class)
                ->whereIn('id', $entries->pluck('employee_id'))
                ->get()
                ->keyBy('id');

            // Las cuentas de pasivo de cargas y provisiones se cargan de una
            // vez y sin el scope de compañía: este service tiene que ser
            // correcto también en un job de fondo, donde no hay compañía
            // ambiental y el scope —que falla cerrado— devolvería nada.
            $liabilities = $this->liabilityAccounts($company);

            // La fecha de PAGO manda para el asiento, no la de fin de
            // período: una quincena que cierra el 30 y se paga el 2 es gasto
            // del mes en que se devengó, pero el pasivo nace cuando se
            // liquida. Se usa la fecha de pago del período salvo que el
            // usuario indique otra explícitamente.
            $date = $postingDate ?? $period->payment_date;

            $lines = [];

            foreach ($entries as $entry) {
                $employee = $employees->get($entry->employee_id);

                foreach ($this->linesFor($company, $settings, $entry, $employee, $liabilities) as $line) {
                    $lines[] = $line;
                }
            }

            $journalEntry = $this->postJournalService->post(
                company: $company,
                documentType: $documentType,
                documentDate: $date,
                postingDate: $date,
                lines: $lines,
                description: "Planilla {$period->name}",
                createdBy: $createdBy,
            );

            $period->update([
                'status' => 'posted',
                'journal_entry_id' => $journalEntry->id,
                'document_type_id' => $documentType->id,
            ]);

            return $period->fresh();
        });
    }

    /**
     * Las líneas de asiento de una boleta: los tres bloques del encabezado.
     *
     * @return JournalLineInput[]
     */
    private function linesFor(
        Company $company,
        PayrollSetting $settings,
        PayrollEntry $entry,
        ?Employee $employee,
        array $liabilities,
    ): array {
        $currencyId = $company->local_currency_id;
        $costCenterId = $entry->cost_center_id;
        $label = $employee?->fullName() ?? "Empleado #{$entry->employee_id}";
        $lines = [];

        // ── Bloque 1 · el bruto al gasto ────────────────────────────────
        // La cuenta sale del empleado si la trae, si no de la compañía: es
        // la misma escalera de herencia que usa la determinación de cuentas
        // de inventario.
        $salaryAccount = $employee?->salary_expense_account_id ?? $settings->salary_expense_account_id;

        $lines[] = new JournalLineInput(
            accountId: $salaryAccount,
            currencyId: $currencyId,
            debit: $entry->total_earnings,
            credit: 0,
            description: "Salarios — {$label}",
            costCenterId: $costCenterId,
        );

        // ── Bloque 1 · las retenciones al pasivo ────────────────────────
        // Agrupadas por cuenta: un trabajador puede tener varias cargas que
        // caen en la misma cuenta de CCSS por pagar, y una línea por cada
        // componente haría un asiento ilegible sin agregar información que
        // la boleta no tenga ya.
        foreach ($this->groupByAccount($entry, PayrollEntryLine::DEDUCTION_KINDS, $settings) as $accountId => $amount) {
            if (bccomp($amount, '0.00', 2) <= 0) {
                continue;
            }

            $lines[] = new JournalLineInput(
                accountId: $accountId,
                currencyId: $currencyId,
                debit: 0,
                credit: $amount,
                description: "Retenciones — {$label}",
            );
        }

        // ── Bloque 1 · el neto como pasivo con el trabajador ────────────
        if (bccomp((string) $entry->net_pay, '0.00', 2) > 0) {
            $lines[] = new JournalLineInput(
                accountId: $settings->net_payable_account_id,
                currencyId: $currencyId,
                debit: 0,
                credit: $entry->net_pay,
                description: "Neto a pagar — {$label}",
            );
        }

        // ── Bloques 2 y 3 · cargas patronales y provisiones ─────────────
        // Cada una carga a su cuenta de gasto y abona a su cuenta de pasivo:
        // por eso se generan en par y no se agrupan como las retenciones.
        foreach ($entry->lines->whereIn('kind', PayrollEntryLine::EMPLOYER_KINDS) as $line) {
            $expenseAccount = $line->account_id;
            $liabilityAccount = $line->payroll_contribution_id !== null
                ? ($liabilities['contribution'][$line->payroll_contribution_id] ?? null)
                : ($liabilities['provision'][$line->payroll_provision_id] ?? null);

            if ($expenseAccount === null || $liabilityAccount === null) {
                throw new InvalidPayrollException(
                    "El concepto {$line->code} ({$line->name}) no tiene cuenta de gasto y de pasivo configuradas; ".
                    'sin ellas la planilla no se puede contabilizar.'
                );
            }

            $lines[] = new JournalLineInput(
                accountId: $expenseAccount,
                currencyId: $currencyId,
                debit: $line->amount,
                credit: 0,
                description: "{$line->name} — {$label}",
                costCenterId: $costCenterId,
            );

            $lines[] = new JournalLineInput(
                accountId: $liabilityAccount,
                currencyId: $currencyId,
                debit: 0,
                credit: $line->amount,
                description: "{$line->name} — {$label}",
            );
        }

        return $lines;
    }

    /**
     * Suma los montos de ciertos tipos de línea por cuenta contable.
     *
     * Una línea sin cuenta propia cae en la de planilla por pagar: es lo
     * honesto —el rebajo existe y hay que deberlo— y hace visible que falta
     * configurarla, en vez de perder el monto y descuadrar el asiento.
     *
     * @param  string[]  $kinds
     * @return array<int, string>
     */
    private function groupByAccount(PayrollEntry $entry, array $kinds, PayrollSetting $settings): array
    {
        $totals = [];

        foreach ($entry->lines->whereIn('kind', $kinds) as $line) {
            $accountId = $line->account_id
                ?? ($line->kind === 'income_tax' ? $settings->income_tax_payable_account_id : null)
                ?? $settings->net_payable_account_id;

            $totals[$accountId] = bcadd($totals[$accountId] ?? '0.00', (string) $line->amount, 2);
        }

        return $totals;
    }

    /**
     * Las cuentas de pasivo de cada carga y cada provisión de la compañía.
     *
     * @return array{contribution: array<int, ?int>, provision: array<int, ?int>}
     */
    private function liabilityAccounts(Company $company): array
    {
        return [
            'contribution' => PayrollContribution::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->pluck('liability_account_id', 'id')->all(),
            'provision' => PayrollProvision::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->pluck('liability_account_id', 'id')->all(),
        ];
    }

    private function documentType(Company $company, PayrollPeriod $period, PayrollSetting $settings): DocumentType
    {
        $id = $period->document_type_id ?? $settings->document_type_id;

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
