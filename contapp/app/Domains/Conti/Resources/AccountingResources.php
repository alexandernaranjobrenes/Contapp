<?php

namespace App\Domains\Conti\Resources;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FxRevaluationRun;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntrySchedule;
use App\Domains\Conti\Support\ContiFormat as F;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Database\Eloquent\Builder;

/** Contabilidad, centros de costo, cambiario e impuestos. */
final class AccountingResources
{
    private const RATE_TYPES = ['reference' => 'Referencia', 'buy' => 'Compra', 'sell' => 'Venta'];

    private const ENTRY_STATUSES = ['draft' => 'Preliminar', 'posted' => 'Contabilizado', 'voided' => 'Anulado'];

    /** @return list<ContiResource> */
    public static function all(): array
    {
        return [
            self::accounts(),
            self::journalEntries(),
            self::schedules(),
            self::fiscalPeriods(),
            self::documentTypes(),
            self::exchangeRates(),
            self::fxRevaluations(),
            self::costCenters(),
            self::allocationRules(),
            self::taxRates(),
        ];
    }

    private static function accounts(): ContiResource
    {
        $row = fn (ChartOfAccount $a) => [
            'id' => $a->id,
            'codigo' => $a->code,
            'nombre' => $a->description_es,
            'nombre_ingles' => $a->description_en,
            'clase' => ChartOfAccount::ACCOUNT_TYPES[$a->account_type] ?? $a->account_type,
            'naturaleza' => $a->normal_balance === 'debit' ? 'débito' : 'crédito',
            'moneda' => ChartOfAccount::CURRENCY_MODES[$a->currency_mode] ?? $a->currency_mode,
            'acepta_movimientos' => F::yesNo($a->accepts_posting),
            'exige_socio' => F::yesNo($a->requires_business_partner),
            'exige_norma_reparto' => F::yesNo($a->requires_cost_center),
            'monetaria' => F::yesNo($a->is_cash_account),
            'clasificacion_iva' => ChartOfAccount::TAX_CLASSIFICATIONS[$a->tax_classification] ?? $a->tax_classification,
            'activa' => F::yesNo($a->is_active),
        ];

        return new ContiResource(
            key: 'cuentas',
            label: 'Catálogo de cuentas',
            description: 'Las cuentas contables: código, nombre, clase, naturaleza y sus exigencias. Para el saldo y los movimientos de una cuenta, usá el reporte «mayor».',
            screens: ['accounting.chart_of_accounts', 'accounting.journal_entries', 'reports.trial_balance'],
            query: fn () => ChartOfAccount::query()->with('taxRate:id,code,percentage'),
            row: $row,
            detail: fn (ChartOfAccount $a) => $row($a) + [
                'indicador_impuesto' => $a->taxRate ? "{$a->taxRate->code} ({$a->taxRate->percentage}%)" : null,
            ],
            search: ['code', 'description_es', 'description_en'],
            filters: [
                'clase' => [ContiFilters::options(ChartOfAccount::ACCOUNT_TYPES), ContiFilters::oneOf('account_type', ChartOfAccount::ACCOUNT_TYPES)],
                'acepta_movimientos' => ['sí | no', ContiFilters::boolean('accepts_posting')],
                'activa' => ['sí | no', ContiFilters::boolean('is_active')],
            ],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function journalEntries(): ContiResource
    {
        $row = fn (JournalEntry $e) => [
            'id' => $e->id,
            'documento' => self::entryLabel($e),
            'tipo_documento' => F::codeName($e->documentType),
            'serie' => $e->series_number ? ($e->numberSeries?->name.' #'.$e->series_number) : null,
            'fecha_documento' => F::date($e->document_date),
            'fecha_contabilizacion' => F::date($e->posting_date),
            'vencimiento' => F::date($e->due_date),
            'descripcion' => $e->description,
            'estado' => self::ENTRY_STATUSES[$e->status] ?? $e->status,
            'total_debitos_moneda_local' => F::money($e->details_sum_debit_local ?? null),
        ];

        return new ContiResource(
            key: 'asientos',
            label: 'Asientos contables (Registros)',
            description: 'Los asientos: preliminares, contabilizados y anulados. El detalle trae las líneas con cuenta, socio, centro de costo, débito y crédito.',
            screens: ['accounting.journal_entries'],
            query: fn () => JournalEntry::query()
                ->with(['documentType:id,code,name', 'numberSeries:id,name'])
                ->withSum('details', 'debit_local'),
            row: $row,
            detail: function (JournalEntry $e) use ($row) {
                $e->loadMissing([
                    'details.account:id,code,description_es', 'details.businessPartner:id,code,name',
                    'details.costCenter:id,code,name', 'details.costAllocationRule:id,code,name', 'details.currency:id,code',
                    'createdBy:id,name', 'postedBy:id,name', 'reversalOf.documentType:id,code',
                ]);
                $first = $e->details->first();

                return $row($e) + [
                    'tipo_cambio' => $first?->exchange_rate_lc_fc ? F::number($first->exchange_rate_lc_fc, 6) : null,
                    'creado_por' => $e->createdBy?->name,
                    'contabilizado_por' => $e->postedBy?->name,
                    'contabilizado_el' => $e->posted_at?->format('Y-m-d H:i'),
                    'anula_a' => $e->reversalOf ? self::entryLabel($e->reversalOf) : null,
                    'lineas' => $e->details->sortBy('line_number')->values()->map(fn (JournalDetail $d) => [
                        'linea' => $d->line_number,
                        'cuenta' => $d->account ? "{$d->account->code} {$d->account->description_es}" : null,
                        'socio' => F::codeName($d->businessPartner),
                        'centro_costo' => F::codeName($d->costCenter),
                        'norma_reparto' => F::codeName($d->costAllocationRule),
                        'moneda' => $d->currency?->code,
                        'debito_local' => F::money($d->debit_local),
                        'credito_local' => F::money($d->credit_local),
                        'debito_extranjera' => F::money($d->debit_foreign),
                        'credito_extranjera' => F::money($d->credit_foreign),
                        'descripcion' => $d->description,
                        'vencimiento' => F::date($d->due_date),
                        'documento_referencia' => $d->reference_document,
                        'clave_electronica' => $d->electronic_key,
                    ])->all(),
                ];
            },
            search: function (Builder $query, string $term) {
                $query->where(fn (Builder $q) => $q->where('description', 'like', "%{$term}%")
                    ->orWhere('document_number', $term)
                    ->orWhereHas('details', fn (Builder $d) => $d->where('description', 'like', "%{$term}%")
                        ->orWhere('reference_document', 'like', "%{$term}%")));
            },
            filters: [
                'estado' => [ContiFilters::options(self::ENTRY_STATUSES), ContiFilters::oneOf('status', self::ENTRY_STATUSES)],
                'tipo_documento' => ['código del tipo de documento, ej. TRB', ContiFilters::relatedCode('documentType')],
                'numero' => ['número de documento (consecutivo)', ContiFilters::equals('document_number')],
                'cuenta' => ['código de una cuenta que aparezca en las líneas', fn (Builder $q, string $v) => $q->whereHas('details.account', fn (Builder $a) => $a->where('code', $v))],
                'socio' => ['código de un socio que aparezca en las líneas', fn (Builder $q, string $v) => $q->whereHas('details.businessPartner', fn (Builder $p) => $p->where('code', $v))],
            ],
            dateColumn: 'posting_date',
            orderBy: ['posting_date' => 'desc', 'id' => 'desc'],
            codeColumn: null,
        );
    }

    private static function schedules(): ContiResource
    {
        return new ContiResource(
            key: 'asientos-programados',
            label: 'Registros programados',
            description: 'Las plantillas de asientos recurrentes: cada cuánto generan un preliminar y cuándo es la próxima corrida.',
            screens: ['accounting.journal_entry_schedules'],
            query: fn () => JournalEntrySchedule::query()->with('documentType:id,code,name')->withCount('generatedEntries'),
            row: fn (JournalEntrySchedule $s) => [
                'id' => $s->id,
                'tipo_documento' => F::codeName($s->documentType),
                'descripcion' => $s->description,
                'frecuencia' => ($s->frequency_type === 'days' ? 'cada '.$s->interval_count.' día(s)' : 'cada '.$s->interval_count.' mes(es)'),
                'proxima_corrida' => F::date($s->next_run_date),
                'fecha_limite' => F::date($s->expires_at),
                'estado' => F::status($s->status),
                'preliminares_generados' => $s->generated_entries_count,
                'lineas' => is_array($s->lines) ? count($s->lines) : null,
            ],
            search: ['description'],
            filters: ['estado' => ['active | cancelled', ContiFilters::equals('status')]],
            orderBy: ['next_run_date' => 'asc'],
            codeColumn: null,
        );
    }

    private static function fiscalPeriods(): ContiResource
    {
        return new ContiResource(
            key: 'periodos-fiscales',
            label: 'Períodos fiscales',
            description: 'Los períodos mensuales de cada año fiscal y si están abiertos, bloqueados o cerrados. Solo se puede contabilizar con fecha en un período abierto.',
            screens: ['accounting.period_close', 'accounting.journal_entries'],
            query: fn () => FiscalPeriod::query()->whereHas('fiscalYear')->with('fiscalYear:id,year,status'),
            row: fn (FiscalPeriod $p) => [
                'id' => $p->id,
                'anio' => $p->fiscalYear?->year,
                'periodo' => $p->period_number,
                'desde' => F::date($p->start_date),
                'hasta' => F::date($p->end_date),
                'estado' => F::status($p->status, ['open' => 'abierto', 'blocked' => 'bloqueado', 'closed' => 'cerrado']),
                'anio_cerrado' => F::yesNo($p->fiscalYear?->status === 'closed'),
            ],
            filters: [
                'anio' => ['año fiscal, ej. 2026', fn (Builder $q, string $v) => $q->whereHas('fiscalYear', fn (Builder $y) => $y->where('year', $v))],
                'estado' => ['open (abierto), blocked (bloqueado), closed (cerrado)', ContiFilters::oneOf('status', ['open' => 'abierto', 'blocked' => 'bloqueado', 'closed' => 'cerrado'])],
            ],
            dateColumn: 'start_date',
            orderBy: ['start_date' => 'desc'],
            codeColumn: null,
        );
    }

    private static function documentTypes(): ContiResource
    {
        $row = fn (DocumentType $t) => [
            'id' => $t->id,
            'codigo' => $t->code,
            'nombre' => $t->name,
            'modulo' => $t->origin_module,
            'genera_asiento' => F::yesNo($t->generates_journal),
            'exige_clave_electronica' => F::yesNo($t->requires_electronic_key),
            'control_socio' => DocumentType::BP_LINE_REQUIREMENTS[$t->bp_line_requirement] ?? $t->bp_line_requirement,
            'siguiente_consecutivo' => $t->next_consecutive,
            'reservado_del_sistema' => match (true) {
                (bool) $t->is_opening_type => 'saldos iniciales (APE)',
                (bool) $t->is_reconciliation_type => 'traspaso de reconciliación (ARR)',
                (bool) $t->is_closing_type => 'cierre anual (ACC)',
                default => null,
            },
            'estado' => F::status($t->status),
        ];

        return new ContiResource(
            key: 'tipos-documento',
            label: 'Tipos de documento',
            description: 'Los tipos de documento de los asientos, con su numeración y lo que exigen. El detalle trae sus series manuales.',
            screens: ['accounting.document_types', 'accounting.journal_entries'],
            query: fn () => DocumentType::query(),
            row: $row,
            detail: fn (DocumentType $t) => $row($t) + [
                'series' => $t->numberSeries()->orderBy('name')->get()->map(fn ($s) => [
                    'nombre' => $s->name,
                    'encargado' => $s->holder_name,
                    'rango' => "{$s->range_from}-{$s->range_to}",
                    'siguiente' => $s->next_number,
                    'activa' => F::yesNo($s->is_active),
                ])->all(),
            ],
            search: ['code', 'name'],
            filters: ['estado' => ['active | inactive', ContiFilters::equals('status')]],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function exchangeRates(): ContiResource
    {
        return new ContiResource(
            key: 'tipos-cambio',
            label: 'Tipos de cambio',
            description: 'El historial de tipos de cambio de la moneda extranjera (colones por dólar), del BCCR o cargados a mano.',
            screens: ['accounting.exchange_rates', 'accounting.journal_entries'],
            query: fn () => ExchangeRate::query()->with('currency:id,code'),
            row: fn (ExchangeRate $r) => [
                'fecha' => F::date($r->rate_date),
                'moneda' => $r->currency?->code,
                'tipo' => self::RATE_TYPES[$r->rate_type] ?? $r->rate_type,
                'tasa' => F::number($r->rate, 6),
                'origen' => $r->source === 'bccr_api' ? 'BCCR' : 'manual',
                'bloqueado' => F::yesNo($r->is_locked),
            ],
            filters: ['tipo' => [ContiFilters::options(self::RATE_TYPES), ContiFilters::oneOf('rate_type', self::RATE_TYPES)]],
            dateColumn: 'rate_date',
            orderBy: ['rate_date' => 'desc', 'rate_type' => 'asc'],
            codeColumn: null,
        );
    }

    private static function fxRevaluations(): ContiResource
    {
        $row = fn (FxRevaluationRun $r) => [
            'id' => $r->id,
            'corte' => F::date($r->cutoff_date),
            'tipo_cambio' => F::number($r->exchange_rate_used, 6),
            'asiento' => $r->journalEntry ? self::entryLabel($r->journalEntry) : null,
            'cuenta_ganancia' => $r->gainAccount ? "{$r->gainAccount->code} {$r->gainAccount->description_es}" : null,
            'cuenta_perdida' => $r->lossAccount ? "{$r->lossAccount->code} {$r->lossAccount->description_es}" : null,
            'ejecutado_por' => $r->executedBy?->name,
            'estado' => F::status($r->status),
        ];

        return new ContiResource(
            key: 'diferencial-cambiario',
            label: 'Diferencial cambiario (historial)',
            description: 'Cada revaluación de saldos en moneda extranjera que se contabilizó. El detalle trae la diferencia por cuenta y socio.',
            screens: ['accounting.fx_revaluation_history', 'accounting.fx_revaluation_run'],
            query: fn () => FxRevaluationRun::query()->with([
                'journalEntry.documentType:id,code', 'gainAccount:id,code,description_es', 'lossAccount:id,code,description_es', 'executedBy:id,name',
            ]),
            row: $row,
            detail: fn (FxRevaluationRun $r) => $row($r) + [
                'detalle' => $r->details()->with(['account:id,code,description_es', 'businessPartner:id,code,name'])->get()->map(fn ($d) => [
                    'cuenta' => $d->account ? "{$d->account->code} {$d->account->description_es}" : null,
                    'socio' => F::codeName($d->businessPartner),
                    'saldo_moneda_extranjera' => F::money($d->foreign_balance),
                    'local_historico' => F::money($d->historical_local_amount),
                    'local_revaluado' => F::money($d->revalued_local_amount),
                    'diferencia' => F::money($d->difference),
                ])->all(),
            ],
            dateColumn: 'cutoff_date',
            orderBy: ['cutoff_date' => 'desc'],
            codeColumn: null,
        );
    }

    private static function costCenters(): ContiResource
    {
        return new ContiResource(
            key: 'centros-costo',
            label: 'Centros de costo',
            description: 'Los destinos de costo y gasto. Los montos les llegan por normas de reparto.',
            screens: ['accounting.cost_centers', 'accounting.cost_allocation_rules', 'reports.cost_center'],
            query: fn () => CostCenter::query(),
            row: fn (CostCenter $c) => [
                'id' => $c->id,
                'codigo' => $c->code,
                'nombre' => $c->name,
                'vigente_desde' => F::date($c->start_date),
                'vigente_hasta' => F::date($c->end_date),
                'activo' => F::yesNo($c->is_active),
            ],
            search: ['code', 'name'],
            filters: ['activo' => ['sí | no', ContiFilters::boolean('is_active')]],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function allocationRules(): ContiResource
    {
        return new ContiResource(
            key: 'normas-reparto',
            label: 'Normas de reparto',
            description: 'Cómo se reparte un monto entre centros de costo, con el porcentaje de cada uno.',
            screens: ['accounting.cost_allocation_rules', 'accounting.journal_entries'],
            query: fn () => CostAllocationRule::query()->with('lines.costCenter:id,code,name'),
            row: fn (CostAllocationRule $r) => [
                'id' => $r->id,
                'codigo' => $r->code,
                'nombre' => $r->name,
                'vigente_desde' => F::date($r->valid_from),
                'vigente_hasta' => F::date($r->valid_until),
                'activa' => F::yesNo($r->is_active),
                'reparto' => $r->lines->sortBy('position')->values()->map(fn ($l) => [
                    'centro_costo' => F::codeName($l->costCenter),
                    'porcentaje' => F::number($l->percentage, 2),
                ])->all(),
            ],
            search: ['code', 'name'],
            filters: ['activa' => ['sí | no', ContiFilters::boolean('is_active')]],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function taxRates(): ContiResource
    {
        return new ContiResource(
            key: 'indicadores-impuesto',
            label: 'Indicadores de impuesto',
            description: 'Los indicadores de impuesto: los nacionales (IVA 13 %, 4 %, 2 %, 1 %…) y los propios de la compañía, con su vigencia.',
            // Como en la aplicación: el catálogo lo consulta cualquiera.
            screens: [],
            query: fn () => TaxRate::query()->with('taxType:id,code,name'),
            row: fn (TaxRate $t) => [
                'id' => $t->id,
                'codigo' => $t->code,
                'nombre' => $t->name,
                'tipo' => F::codeName($t->taxType),
                'porcentaje' => F::number($t->percentage, 2),
                'credito_fiscal' => F::yesNo($t->grants_fiscal_credit),
                'origen' => $t->company_id ? 'propio de la compañía' : 'nacional',
                'vigente_desde' => F::date($t->effective_from),
                'vigente_hasta' => F::date($t->effective_to),
            ],
            search: ['code', 'name'],
            dateColumn: null,
            orderBy: ['code' => 'asc'],
        );
    }

    /** «TRB-15», o «TRB (preliminar #8)» si todavía no tiene número. */
    public static function entryLabel(JournalEntry $entry): string
    {
        $code = $entry->documentType?->code ?? 'DOC';

        return $entry->document_number ? "{$code}-{$entry->document_number}" : "{$code} (preliminar #{$entry->id})";
    }
}
