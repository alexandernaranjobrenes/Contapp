<?php

namespace App\Domains\Accounting\Reports;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * Libro mayor: los movimientos de cada cuenta, con su saldo corriendo.
 *
 * ── En qué se diferencia del balance de comprobación ────────────────────
 *
 * El balance de comprobación da UN renglón por cuenta: saldo anterior,
 * débitos, créditos, saldo final. Sirve para ver si el total cuadra.
 *
 * El mayor da TODOS los movimientos de cada cuenta en orden, con el saldo
 * después de cada uno. Es el que se usa cuando el balance muestra un número
 * raro y hay que ir a ver de dónde salió — y es el libro que se presenta
 * cuando alguien lo pide formalmente.
 *
 * ── El saldo anterior es lo que lo hace un mayor y no una lista ─────────
 *
 * Cada cuenta arranca con el saldo que traía al día anterior al rango, en una
 * fila propia marcada «Saldo anterior». Sin eso, el saldo corrido de la
 * primera fila sería el del primer movimiento del mes y no el saldo real de
 * la cuenta: un mayor de octubre que arranca en cero es un mayor equivocado.
 *
 * ── Tres reglas heredadas del mayor auxiliar, que no son obvias ────────
 *
 * 1. Cuenta lo contabilizado Y lo anulado. Anular no borra: marca el original
 *    y contabiliza un espejo con signo contrario. Los dos tienen que contar
 *    para que el par neteé en cero (bug real corregido el 2026-09-09, y otra
 *    vez en la conciliación bancaria el 2026-10-05).
 *
 * 2. El signo del saldo depende de la naturaleza de la cuenta: en una de
 *    activo el débito suma, en una de pasivo resta. Mostrar siempre
 *    débito−crédito pondría en negativo todos los pasivos y el patrimonio.
 *
 * 3. El asiento de cierre anual se excluye de las cuentas de RESULTADOS. Ese
 *    asiento las lleva a cero para cerrar el año; incluirlo escondería la
 *    actividad real del período detrás del movimiento que la cancela. En las
 *    cuentas de balance sí se muestra: ahí es donde el resultado aterriza.
 */
class GeneralLedgerReport implements AccountingReport
{
    /**
     * Tope de filas. Un mayor de todo el catálogo y todo el año son cientos de
     * miles de movimientos: sin tope, la consulta se lleva la memoria del
     * servidor y el usuario ve una pantalla colgada en vez de un aviso.
     */
    private const MAX_ROWS = 5000;

    /** Las cuentas cuyo saldo cancela el asiento de cierre anual. */
    private const PROFIT_AND_LOSS_TYPES = ['income', 'cost_of_sales', 'expense', 'other_income', 'other_expense'];

    public function code(): string
    {
        return 'general-ledger';
    }

    public function label(): string
    {
        return 'Libro mayor';
    }

    public function description(): string
    {
        return 'Los movimientos de cada cuenta en orden, con el saldo anterior y el saldo después de cada uno.';
    }

    public function decision(): string
    {
        return 'De dónde salió el saldo de una cuenta, movimiento por movimiento.';
    }

    public function group(): string
    {
        return 'Libros legales';
    }

    public function frozenColumns(): int
    {
        return 2;
    }

    /** @return ReportFilter[] */
    public function filters(): array
    {
        return [
            new ReportFilter('from', 'Desde', ReportFilter::DATE, default: 'first_day_of_month',
                hint: 'Por fecha de contabilización. Lo anterior se resume en el saldo anterior de cada cuenta.'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('account_id', 'Cuenta', ReportFilter::SELECT, optionSource: 'posting_accounts',
                hint: 'Una cuenta concreta. En blanco trae todas las que tuvieron movimiento.'),
            new ReportFilter('account_from', 'Código desde', ReportFilter::TEXT,
                hint: 'Rango de códigos: «1-01» trae todo el efectivo.'),
            new ReportFilter('account_to', 'Código hasta', ReportFilter::TEXT),
            new ReportFilter('document_type_id', 'Tipo de documento', ReportFilter::SELECT,
                optionSource: 'document_types'),
            new ReportFilter('document_number', 'N.º de documento', ReportFilter::TEXT,
                hint: 'Parte del número: «4521».'),
            new ReportFilter('hide_zero', 'Ocultar cuentas sin movimiento en el rango', ReportFilter::BOOLEAN,
                hint: 'Deja fuera las que solo traen saldo anterior.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $columns = [
            new ReportColumn('account_code', 'Cuenta'),
            new ReportColumn('account_name', 'Descripción de la cuenta'),
            new ReportColumn('date', 'Fecha', ReportColumn::DATE),
            new ReportColumn('document', 'Documento'),
            new ReportColumn('entry', 'Asiento'),
            new ReportColumn('description', 'Detalle'),
            new ReportColumn('partner', 'Socio'),
            new ReportColumn('cost_center', 'C. costo'),
            new ReportColumn('debit', 'Débito', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('credit', 'Crédito', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('balance', 'Saldo', ReportColumn::MONEY),
        ];

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $accounts = $this->accounts($company, $filters);

        if ($accounts->isEmpty()) {
            return new ReportResult($columns, [], notes: ['Ningún código de cuenta coincide con el filtro.']);
        }

        $closingTypeIds = DocumentType::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('is_closing_type', true)
            ->pluck('id');

        $openings = $this->openingBalances($company, $accounts, $from, $closingTypeIds, $filters);
        $movements = $this->movements($company, $accounts, $from, $to, $closingTypeIds, $filters);

        $rows = [];
        $truncated = false;

        foreach ($accounts as $account) {
            $own = $movements->get($account->id, collect());

            if (($filters['hide_zero'] ?? false) && $own->isEmpty()) {
                continue;
            }

            $opening = $openings[$account->id] ?? '0.00';

            // Una cuenta sin saldo anterior ni movimientos no aporta nada: en
            // un catálogo de mil cuentas, listarlas todas en blanco hace que
            // el mayor no se pueda leer.
            if ($own->isEmpty() && bccomp($opening, '0.00', 2) === 0) {
                continue;
            }

            $rows[] = [
                'account_code' => $account->code,
                'account_name' => $account->description_es,
                'date' => $from,
                'document' => null,
                'entry' => null,
                'description' => 'Saldo anterior',
                'partner' => null,
                'cost_center' => null,
                // El saldo anterior NO lleva débito ni crédito: si los
                // llevara, los totales de la columna sumarían dos veces lo
                // que ya está dentro del saldo.
                'debit' => null,
                'credit' => null,
                'balance' => (float) $opening,
            ];

            $running = $opening;

            foreach ($own as $movement) {
                if (count($rows) >= self::MAX_ROWS) {
                    $truncated = true;

                    break 2;
                }

                $running = bcadd(
                    $running,
                    $this->signed($account->normal_balance, (string) $movement->debit_local, (string) $movement->credit_local),
                    2
                );

                $rows[] = [
                    'account_code' => $account->code,
                    'account_name' => $account->description_es,
                    'date' => (string) $movement->posting_date,
                    'document' => $movement->document_type_code.'-'.$movement->document_number,
                    'entry' => $movement->journal_entry_id,
                    'description' => $movement->line_description ?: ($movement->entry_description ?: ''),
                    'partner' => $movement->partner_name,
                    'cost_center' => $movement->cost_center_code,
                    'debit' => (float) $movement->debit_local,
                    'credit' => (float) $movement->credit_local,
                    'balance' => (float) $running,
                ];
            }
        }

        $notes = [
            'El «saldo anterior» de cada cuenta resume todo lo contabilizado antes del rango; no lleva débito '.
            'ni crédito para que los totales de esas columnas sigan siendo los del período.',
            'El saldo respeta la naturaleza de la cuenta: en una de activo el débito suma, en una de pasivo o '.
            'patrimonio resta.',
            'Incluye los asientos anulados junto con su reversión: el par neteá en cero y así el saldo coincide '.
            'con el del balance de comprobación.',
        ];

        if ($closingTypeIds->isNotEmpty()) {
            $notes[] = 'El asiento de cierre anual se excluye de las cuentas de resultados —las lleva a cero para '.
                'cerrar el año— y se muestra en las de balance, que es donde el resultado aterriza.';
        }

        if ($truncated) {
            $notes[] = sprintf(
                'Se alcanzó el tope de %s filas y el reporte quedó cortado. Acotá el rango de fechas o de '.
                'cuentas para verlo completo.',
                number_format(self::MAX_ROWS, 0, ',', '.')
            );
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }

    /**
     * Las cuentas que entran, en orden de código.
     *
     * Solo las que aceptan movimiento: una cuenta de mayor (no hoja) nunca
     * tiene líneas propias, y listarla vacía llena el reporte de ruido.
     */
    private function accounts(Company $company, array $filters)
    {
        return ChartOfAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('accepts_posting', true)
            ->when(($filters['account_id'] ?? null) !== null, fn ($q) => $q->whereKey($filters['account_id']))
            ->when(($filters['account_from'] ?? null) !== null, fn ($q) => $q->where('code', '>=', $filters['account_from']))
            ->when(($filters['account_to'] ?? null) !== null, fn ($q) => $q->where('code', '<=', $filters['account_to']))
            ->orderBy('code')
            ->get(['id', 'code', 'description_es', 'account_type', 'normal_balance']);
    }

    /**
     * El saldo de cada cuenta al día anterior al rango, en UNA consulta
     * agrupada.
     *
     * Una consulta por cuenta sería correcta y costaría una ida a la base por
     * cada una: con un catálogo de quinientas cuentas, el reporte tardaría más
     * en abrir que en leerse.
     *
     * @return array<int, string>
     */
    private function openingBalances(Company $company, $accounts, ?string $from, $closingTypeIds, array $filters): array
    {
        if ($from === null) {
            return [];
        }

        $byType = $accounts->groupBy(fn (ChartOfAccount $a) => in_array($a->account_type, self::PROFIT_AND_LOSS_TYPES, true) ? 'pl' : 'balance');

        $opening = [];

        foreach ($byType as $kind => $group) {
            $rows = JournalDetail::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
                ->where('journal_entries.company_id', $company->id)
                ->whereIn('journal_entries.status', ['posted', 'voided'])
                ->whereIn('journal_details.account_id', $group->pluck('id'))
                ->where('journal_entries.posting_date', '<', $from)
                // El cierre anual solo se excluye de las de resultados.
                ->when(
                    $kind === 'pl' && $closingTypeIds->isNotEmpty(),
                    fn ($q) => $q->whereNotIn('journal_entries.document_type_id', $closingTypeIds)
                )
                ->groupBy('journal_details.account_id')
                ->selectRaw('journal_details.account_id, COALESCE(SUM(debit_local), 0) as debit, COALESCE(SUM(credit_local), 0) as credit')
                ->get();

            foreach ($rows as $row) {
                $account = $group->firstWhere('id', $row->account_id);

                $opening[$row->account_id] = $this->signed(
                    $account->normal_balance, (string) $row->debit, (string) $row->credit
                );
            }
        }

        return $opening;
    }

    /**
     * Los movimientos del rango, agrupados por cuenta y en orden
     * cronológico dentro de cada una.
     */
    private function movements(Company $company, $accounts, ?string $from, ?string $to, $closingTypeIds, array $filters)
    {
        $plAccountIds = $accounts
            ->filter(fn (ChartOfAccount $a) => in_array($a->account_type, self::PROFIT_AND_LOSS_TYPES, true))
            ->pluck('id');

        return JournalDetail::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->join('document_types', 'document_types.id', '=', 'journal_entries.document_type_id')
            ->leftJoin('business_partners', 'business_partners.id', '=', 'journal_details.business_partner_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_details.cost_center_id')
            ->where('journal_entries.company_id', $company->id)
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->whereIn('journal_details.account_id', $accounts->pluck('id'))
            ->when($from !== null, fn ($q) => $q->where('journal_entries.posting_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('journal_entries.posting_date', '<=', $to))
            ->when(($filters['document_type_id'] ?? null) !== null,
                fn ($q) => $q->where('journal_entries.document_type_id', $filters['document_type_id']))
            ->when(($filters['document_number'] ?? null) !== null,
                fn ($q) => $q->where('journal_entries.document_number', 'like', '%'.$filters['document_number'].'%'))
            // El cierre anual fuera de las cuentas de resultados, dentro de
            // las de balance: por eso la condición mira la cuenta de la fila.
            ->when(
                $closingTypeIds->isNotEmpty() && $plAccountIds->isNotEmpty(),
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereNotIn('journal_entries.document_type_id', $closingTypeIds)
                    ->orWhereNotIn('journal_details.account_id', $plAccountIds)
                )
            )
            ->orderBy('journal_details.account_id')
            ->orderBy('journal_entries.posting_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_details.line_number')
            ->get([
                'journal_details.account_id',
                'journal_details.journal_entry_id',
                'journal_entries.posting_date',
                'journal_entries.document_number',
                'journal_entries.description as entry_description',
                'document_types.code as document_type_code',
                'journal_details.description as line_description',
                'journal_details.debit_local',
                'journal_details.credit_local',
                'business_partners.name as partner_name',
                'cost_centers.code as cost_center_code',
            ])
            ->groupBy('account_id');
    }

    /** Débito menos crédito, o al revés, según la naturaleza de la cuenta. */
    private function signed(string $normalBalance, string $debit, string $credit): string
    {
        return $normalBalance === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
    }
}
