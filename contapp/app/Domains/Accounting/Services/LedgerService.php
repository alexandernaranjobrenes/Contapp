<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\LedgerMovement;
use App\Domains\Accounting\DataTransferObjects\LedgerResult;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;

/**
 * Mayor auxiliar genérico: saldo inicial + movimientos + saldo acumulado,
 * calculado siempre desde journal_details (nunca almacenado, CLAUDE.md),
 * reutilizado por catálogo de cuentas, socios de negocio y centros de costo
 * — las tres "dimensiones" contra las que puede filtrarse una línea de
 * asiento. Cuenta lo contabilizado Y lo anulado (status posted o voided);
 * borradores no son movimientos reales todavía y sí quedan afuera. Un
 * asiento voided NUNCA se borra ni se excluye del saldo — solo queda
 * marcado para no dejarlo anular dos veces (PostJournalService::reverse()) —
 * su reversión es un asiento espejo aparte con signo contrario, y ambos
 * deben contar para que el par neteé exactamente en cero. Excluirlo (como
 * hacía antes) deja el espejo sin nada que cancelar y descuadra la cuenta
 * por el monto completo de la reversión (bug real encontrado y corregido
 * 2026-09-09, ver docs/decisiones.md).
 */
class LedgerService
{
    private const MAX_MOVEMENTS = 1000;

    private const DIMENSIONS = [
        'account' => ['model' => ChartOfAccount::class, 'column' => 'account_id', 'name_field' => 'description_es'],
        'business-partner' => ['model' => BusinessPartner::class, 'column' => 'business_partner_id', 'name_field' => 'name'],
        'cost-center' => ['model' => CostCenter::class, 'column' => 'cost_center_id', 'name_field' => 'name'],
    ];

    public function build(Company $company, string $dimension, int $ownerId, ?string $from, ?string $to): LedgerResult
    {
        $config = self::DIMENSIONS[$dimension]
            ?? throw new \InvalidArgumentException("Dimensión de mayor desconocida: {$dimension}.");

        /** @var ChartOfAccount|BusinessPartner|CostCenter $owner */
        $owner = $config['model']::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->findOrFail($ownerId);

        $normalBalance = $this->resolveNormalBalance($dimension, $owner);
        $column = $config['column'];

        // El asiento de cierre anual (DocumentType::is_closing_type, ver
        // PeriodCloseService::closingDocumentType()) reparte a cero cada
        // cuenta de resultados para que el año quede en cero — incluirlo acá
        // escondería la actividad real del año detrás del único movimiento
        // que la cancela. Una cuenta que NO es de resultados (patrimonio,
        // activo, pasivo — ej. utilidades acumuladas) sí debe seguir
        // viéndolo: ahí es justo donde el resultado neto termina aterrizando.
        $plAccountTypes = ['income', 'cost_of_sales', 'expense', 'other_income', 'other_expense'];
        $excludeClosingEntries = $dimension === 'account' && in_array($owner->account_type, $plAccountTypes, true);

        $excludedDocumentTypeIds = $excludeClosingEntries
            ? DocumentType::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->where('is_closing_type', true)
                ->pluck('id')
            : collect();

        $baseQuery = fn () => JournalDetail::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->whereIn('journal_entries.status', ['posted', 'voided'])
            ->where("journal_details.{$column}", $owner->id)
            ->when(
                $excludedDocumentTypeIds->isNotEmpty(),
                fn ($q) => $q->whereNotIn('journal_entries.document_type_id', $excludedDocumentTypeIds)
            );

        $opening = '0.00';

        if ($from !== null) {
            $prior = $baseQuery()
                ->where('journal_entries.posting_date', '<', $from)
                ->selectRaw('COALESCE(SUM(debit_local), 0) as debit, COALESCE(SUM(credit_local), 0) as credit')
                ->first();

            $opening = $this->signedBalance($normalBalance, (string) $prior->debit, (string) $prior->credit);
        }

        $rangeQuery = $baseQuery();

        if ($from !== null) {
            $rangeQuery->where('journal_entries.posting_date', '>=', $from);
        }

        if ($to !== null) {
            $rangeQuery->where('journal_entries.posting_date', '<=', $to);
        }

        $rows = $rangeQuery
            ->join('document_types', 'document_types.id', '=', 'journal_entries.document_type_id')
            ->orderBy('journal_entries.posting_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_details.line_number')
            ->limit(self::MAX_MOVEMENTS)
            ->get([
                'journal_entries.posting_date',
                'journal_entries.document_number',
                'document_types.code as document_type_code',
                'journal_entries.description as entry_description',
                'journal_details.description as line_description',
                'journal_details.debit_local',
                'journal_details.credit_local',
            ]);

        $running = $opening;
        $movements = [];

        foreach ($rows as $row) {
            $running = bcadd($running, $this->signedBalance($normalBalance, (string) $row->debit_local, (string) $row->credit_local), 2);

            $movements[] = new LedgerMovement(
                date: (string) $row->posting_date,
                document: "{$row->document_type_code}-{$row->document_number}",
                description: $row->line_description ?: ($row->entry_description ?: ''),
                debit: number_format((float) $row->debit_local, 2, '.', ''),
                credit: number_format((float) $row->credit_local, 2, '.', ''),
                balance: $running,
            );
        }

        return new LedgerResult(
            ownerCode: $owner->code,
            ownerName: $owner->{$config['name_field']},
            normalBalance: $normalBalance,
            from: $from,
            to: $to,
            openingBalance: $opening,
            closingBalance: $running,
            movements: $movements,
            truncated: $rows->count() >= self::MAX_MOVEMENTS,
        );
    }

    private function resolveNormalBalance(string $dimension, ChartOfAccount|BusinessPartner|CostCenter $owner): string
    {
        // Nada de $owner->glAccount (relación): esa subconsulta vuelve a
        // pasar por el CompanyScope de ChartOfAccount y este service está
        // documentado para funcionar sin CurrentCompany ambiental — mismo
        // bug ya encontrado y corregido para el chequeo de hijos en
        // centros de costo (docs/decisiones.md 2026-08-16). Bypass explícito.
        return match ($dimension) {
            'account' => $owner->normal_balance,
            'business-partner' => ChartOfAccount::withoutGlobalScope(CompanyScope::class)
                ->find($owner->gl_account_id)?->normal_balance ?? 'debit',
            default => 'debit',
        };
    }

    private function signedBalance(string $normalBalance, string $debit, string $credit): string
    {
        return $normalBalance === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
    }
}
