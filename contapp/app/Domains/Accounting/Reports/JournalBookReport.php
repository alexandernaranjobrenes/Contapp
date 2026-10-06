<?php

namespace App\Domains\Accounting\Reports;

use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Core\Models\Company;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportFilter;
use App\Domains\Reporting\Reports\ReportResult;

/**
 * Libro diario: todos los asientos en orden cronológico, línea por línea.
 *
 * ── Es el otro libro, y responde la pregunta contraria al mayor ─────────
 *
 * El mayor ordena por CUENTA: todo lo que le pasó a la cuenta de bancos. El
 * diario ordena por FECHA: todo lo que se contabilizó el 15 de octubre, cada
 * asiento completo con sus dos lados.
 *
 * Por eso las líneas de un mismo asiento van juntas y en el orden en que se
 * digitaron: un asiento partido o desordenado deja de poder leerse como lo
 * que es, y el diario sirve precisamente para seguir el razonamiento de
 * quien lo hizo.
 *
 * ── Filtrar por cuenta no parte el asiento, lo señala ───────────────────
 *
 * Si se filtra por una cuenta, lo natural sería mostrar solo las líneas de
 * esa cuenta — pero entonces los asientos dejarían de cuadrar en pantalla y
 * el total de débitos no sería igual al de créditos, que es justo lo que un
 * diario tiene que dejar ver.
 *
 * Así que el filtro por cuenta selecciona los ASIENTOS que la tocan y los
 * muestra COMPLETOS. Se dice en la nota, para que nadie crea que el reporte
 * trae líneas de más.
 *
 * ── Qué se incluye ──────────────────────────────────────────────────────
 *
 * Lo contabilizado y lo anulado, igual que el mayor y por la misma razón: el
 * anulado y su espejo netean en cero. Los borradores quedan afuera — no son
 * movimientos todavía, y un libro diario con preliminares no es un libro.
 */
class JournalBookReport implements AccountingReport
{
    private const MAX_ROWS = 5000;

    public function code(): string
    {
        return 'journal-book';
    }

    public function label(): string
    {
        return 'Libro diario';
    }

    public function description(): string
    {
        return 'Todos los asientos en orden cronológico, con sus líneas completas.';
    }

    public function decision(): string
    {
        return 'Qué se contabilizó en un período y cómo quedó armado cada asiento.';
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
                hint: 'Por fecha de contabilización.'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('account_id', 'Cuenta', ReportFilter::SELECT, optionSource: 'posting_accounts',
                hint: 'Trae los asientos que tocan esa cuenta, completos.'),
            new ReportFilter('document_type_id', 'Tipo de documento', ReportFilter::SELECT,
                optionSource: 'document_types'),
            new ReportFilter('document_number', 'N.º de documento', ReportFilter::TEXT,
                hint: 'Parte del número: «4521».'),
            new ReportFilter('business_partner_id', 'Socio de negocio', ReportFilter::SELECT,
                optionSource: 'business_partners'),
            new ReportFilter('include_voided', 'Incluir asientos anulados', ReportFilter::BOOLEAN,
                default: true,
                hint: 'Un anulado y su reversión netean en cero; excluirlos descuadra el libro.'),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $columns = [
            new ReportColumn('date', 'Fecha', ReportColumn::DATE),
            new ReportColumn('document', 'Documento'),
            new ReportColumn('entry', 'Asiento'),
            new ReportColumn('entry_description', 'Glosa del asiento'),
            new ReportColumn('account_code', 'Cuenta'),
            new ReportColumn('account_name', 'Descripción'),
            new ReportColumn('description', 'Detalle de la línea'),
            new ReportColumn('partner', 'Socio'),
            new ReportColumn('cost_center', 'C. costo'),
            new ReportColumn('debit', 'Débito', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('credit', 'Crédito', ReportColumn::MONEY, totalizable: true),
            new ReportColumn('status', 'Estado'),
        ];

        $statuses = ($filters['include_voided'] ?? true) ? ['posted', 'voided'] : ['posted'];

        // Primero QUÉ asientos entran; después sus líneas completas. Son dos
        // pasos a propósito: filtrar por cuenta o por socio escoge el asiento,
        // no la línea (ver el encabezado).
        $entryIds = JournalDetail::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->whereIn('journal_entries.status', $statuses)
            ->when(($filters['from'] ?? null) !== null,
                fn ($q) => $q->where('journal_entries.posting_date', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null,
                fn ($q) => $q->where('journal_entries.posting_date', '<=', $filters['to']))
            ->when(($filters['account_id'] ?? null) !== null,
                fn ($q) => $q->where('journal_details.account_id', $filters['account_id']))
            ->when(($filters['business_partner_id'] ?? null) !== null,
                fn ($q) => $q->where('journal_details.business_partner_id', $filters['business_partner_id']))
            ->when(($filters['document_type_id'] ?? null) !== null,
                fn ($q) => $q->where('journal_entries.document_type_id', $filters['document_type_id']))
            ->when(($filters['document_number'] ?? null) !== null,
                fn ($q) => $q->where('journal_entries.document_number', 'like', '%'.$filters['document_number'].'%'))
            ->distinct()
            ->pluck('journal_entries.id');

        if ($entryIds->isEmpty()) {
            return new ReportResult($columns, [], notes: ['No hay asientos que coincidan con los filtros.']);
        }

        $lines = JournalDetail::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_details.journal_entry_id')
            ->join('document_types', 'document_types.id', '=', 'journal_entries.document_type_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_details.account_id')
            ->leftJoin('business_partners', 'business_partners.id', '=', 'journal_details.business_partner_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_details.cost_center_id')
            ->whereIn('journal_entries.id', $entryIds)
            // El orden del libro: por fecha, por asiento, y dentro del asiento
            // por el número de línea con que se digitó.
            ->orderBy('journal_entries.posting_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_details.line_number')
            ->limit(self::MAX_ROWS + 1)
            ->get([
                'journal_entries.id as entry_id',
                'journal_entries.posting_date',
                'journal_entries.document_number',
                'journal_entries.description as entry_description',
                'journal_entries.status',
                'document_types.code as document_type_code',
                'chart_of_accounts.code as account_code',
                'chart_of_accounts.description_es as account_name',
                'journal_details.description as line_description',
                'journal_details.debit_local',
                'journal_details.credit_local',
                'business_partners.name as partner_name',
                'cost_centers.code as cost_center_code',
            ]);

        $truncated = $lines->count() > self::MAX_ROWS;

        $rows = $lines->take(self::MAX_ROWS)->map(fn ($l) => [
            'date' => (string) $l->posting_date,
            'document' => $l->document_type_code.'-'.$l->document_number,
            'entry' => $l->entry_id,
            'entry_description' => $l->entry_description,
            'account_code' => $l->account_code,
            'account_name' => $l->account_name,
            'description' => $l->line_description,
            'partner' => $l->partner_name,
            'cost_center' => $l->cost_center_code,
            'debit' => (float) $l->debit_local,
            'credit' => (float) $l->credit_local,
            'status' => $l->status === 'voided' ? 'ANULADO' : 'Contabilizado',
        ])->values()->all();

        $notes = [
            'Los asientos salen completos, con sus dos lados: por eso el total de débitos tiene que ser igual '.
            'al de créditos.',
        ];

        if (($filters['account_id'] ?? null) !== null || ($filters['business_partner_id'] ?? null) !== null) {
            $notes[] = 'El filtro por cuenta o por socio escoge los ASIENTOS que la tocan y los muestra enteros, '.
                'incluidas las líneas de otras cuentas. Si mostrara solo las líneas filtradas, los asientos no '.
                'cuadrarían en pantalla.';
        }

        if (($filters['include_voided'] ?? true)) {
            $notes[] = 'Incluye los anulados, marcados como tales. Cada uno tiene su asiento de reversión en el '.
                'libro, y el par neteá en cero.';
        } else {
            $notes[] = 'Los anulados están excluidos, pero sus asientos de REVERSIÓN siguen apareciendo: el total '.
                'de este libro no va a coincidir con el balance.';
        }

        if ($truncated) {
            $notes[] = sprintf(
                'Se alcanzó el tope de %s filas y el reporte quedó cortado. Acotá el rango de fechas para verlo '.
                'completo.',
                number_format(self::MAX_ROWS, 0, ',', '.')
            );
        }

        return new ReportResult($columns, $rows, notes: $notes);
    }
}
