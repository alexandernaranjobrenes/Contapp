<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una liquidación laboral. Ver el encabezado de la migración sobre por qué
 * es un documento con sus bases congeladas.
 */
class LaborSettlement extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUSES = [
        'draft' => 'Borrador',
        'approved' => 'Aprobada',
        'posted' => 'Contabilizada',
        'voided' => 'Anulada',
    ];

    public const RECALCULABLE = ['draft'];

    public const REASONS = [
        'despido_con_responsabilidad' => 'Despido con responsabilidad patronal',
        'despido_sin_responsabilidad' => 'Despido sin responsabilidad (justa causa)',
        'renuncia' => 'Renuncia voluntaria',
        'renuncia_con_justa_causa' => 'Renuncia con justa causa (despido indirecto)',
        'vencimiento_plazo' => 'Vencimiento de contrato a plazo u obra',
        'cese_anticipado_plazo' => 'Cese anticipado patronal de plazo fijo',
        'fallecimiento' => 'Fallecimiento del trabajador',
        'jubilacion' => 'Jubilación o pensión',
        'despido_nulo' => 'Despido nulo (fueros de protección)',
        'mutuo_acuerdo' => 'Mutuo acuerdo',
    ];

    /**
     * Qué extremos procede pagar según la causal.
     *
     * ── Esta matriz es la pieza legal del módulo ─────────────────────────
     *
     * Sale de la «Matriz Resumen de Extremos Liquidables» del Manual de
     * Liquidaciones Laborales de Costa Rica (edición 2026) aportado por el
     * usuario, y de los artículos que cita: 28, 29, 31, 35, 81, 82, 83, 84,
     * 85, 86 y 94 bis del Código de Trabajo.
     *
     * El aguinaldo y las vacaciones son DERECHOS IRRENUNCIABLES: se pagan
     * siempre, cualquiera sea la causal, incluso en un despido con justa
     * causa. El preaviso y la cesantía son INDEMNIZACIONES: dependen de
     * quién y por qué terminó la relación.
     *
     * Está como datos y no como una cadena de condicionales porque es una
     * tabla legal: se lee de un vistazo, se compara con la ley renglón por
     * renglón, y el día que cambie se corrige en un lugar.
     *
     * @var array<string, array<string, bool>>
     */
    public const ENTITLEMENTS = [
        //                                 aguinaldo  vacaciones  preaviso  cesantía  indemn.
        'despido_con_responsabilidad' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => true, 'severance' => true, 'indemnity' => false],
        'despido_sin_responsabilidad' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => false, 'severance' => false, 'indemnity' => false],
        // El trabajador es quien DEBE el preaviso al renunciar; no se le
        // paga. Rebajárselo requiere su autorización expresa, así que no se
        // hace solo: se avisa y se registra como deducción si corresponde.
        'renuncia' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => false, 'severance' => false, 'indemnity' => false],
        'renuncia_con_justa_causa' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => true, 'severance' => true, 'indemnity' => false],
        'vencimiento_plazo' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => false, 'severance' => false, 'indemnity' => false],
        'cese_anticipado_plazo' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => false, 'severance' => false, 'indemnity' => true],
        // Se liquida a los causahabientes por consignación judicial, sin
        // juicio sucesorio (Título Décimo CT).
        'fallecimiento' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => false, 'severance' => true, 'indemnity' => false],
        'jubilacion' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => false, 'severance' => true, 'indemnity' => false],
        'despido_nulo' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => true, 'severance' => true, 'indemnity' => true],
        // Los montos indemnizatorios los pactan las partes: la matriz solo
        // garantiza los irrenunciables.
        'mutuo_acuerdo' => ['christmas_bonus' => true, 'vacation' => true, 'notice' => false, 'severance' => false, 'indemnity' => true],
    ];

    /**
     * Auxilio de cesantía: días indemnizables por año (CT art. 29, reformado
     * por la Ley 7983).
     *
     * No es una progresión: sube hasta el año 7, se mantiene tres años y
     * vuelve a bajar. Cualquier fórmula que se invente para «simplificarla»
     * da mal en los extremos — por eso va como tabla literal.
     *
     * @var array<int, string>
     */
    public const SEVERANCE_DAYS_BY_YEAR = [
        1 => '19.5000',
        2 => '20.0000',
        3 => '20.5000',
        4 => '21.0000',
        5 => '21.2400',
        6 => '21.5000',
        7 => '22.0000',
        8 => '22.0000',
        9 => '22.0000',
        10 => '21.5000',
        11 => '21.0000',
        12 => '20.5000',
        // Año 13 en adelante.
        13 => '20.0000',
    ];

    /**
     * Cesantía del primer año, cuando todavía no se cumple uno completo.
     * Menos de tres meses no genera cesantía.
     */
    public const SEVERANCE_FIRST_YEAR = [
        3 => '7.0000',   // de 3 a 6 meses
        6 => '14.0000',  // de 6 meses a 1 año
    ];

    /**
     * El tope: la cesantía se limita a los ocho años (CT art. 29).
     *
     * Suma de la tabla para los años 1 al 8 = 167,74 días. Alguien con
     * veinte años de servicio cobra lo mismo que alguien con ocho.
     */
    public const SEVERANCE_YEAR_CAP = 8;

    /**
     * Preaviso: días de salario según antigüedad (CT art. 28).
     *
     * La clave son los meses cumplidos a partir de los cuales aplica.
     *
     * @var array<int, array{days: string, label: string}>
     */
    public const NOTICE_SCALE = [
        3 => ['days' => '7.0000', 'label' => 'una semana (de 3 a 6 meses de servicio)'],
        6 => ['days' => '15.0000', 'label' => 'quince días (de 6 meses a 1 año de servicio)'],
        12 => ['days' => '30.0000', 'label' => 'un mes (más de 1 año de servicio)'],
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'termination_date', 'reason', 'reason_detail',
        'average_monthly_salary', 'average_daily_salary', 'vacation_daily_salary',
        'christmas_bonus_base', 'years_of_service',
        'bases_from_history', 'history_months_found',
        'total_gross', 'total_ccss', 'total_income_tax', 'total_other_deductions', 'total_net',
        'status', 'journal_entry_id', 'document_type_id', 'cost_center_id',
        'calculated_at', 'approved_at', 'approved_by', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'termination_date' => 'date',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'bases_from_history' => 'boolean',
            'average_monthly_salary' => 'decimal:2',
            'average_daily_salary' => 'decimal:2',
            'vacation_daily_salary' => 'decimal:2',
            'christmas_bonus_base' => 'decimal:2',
            'years_of_service' => 'decimal:4',
            'total_gross' => 'decimal:2',
            'total_ccss' => 'decimal:2',
            'total_income_tax' => 'decimal:2',
            'total_other_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LaborSettlementLine::class)->orderBy('line_number');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isRecalculable(): bool
    {
        return in_array($this->status, self::RECALCULABLE, true);
    }

    /** @return array<string, bool> */
    public function entitlements(): array
    {
        return self::ENTITLEMENTS[$this->reason] ?? self::ENTITLEMENTS['renuncia'];
    }

    /**
     * Días de cesantía que corresponden a una antigüedad dada.
     *
     * Se cuentan los años COMPLETOS, y se agrega uno más si la fracción
     * sobrante supera los seis meses (CT art. 29). Todo acotado al tope de
     * ocho años.
     *
     * @return array{days: string, detail: string}
     */
    public static function severanceDaysFor(int $completedYears, int $remainingMonths): array
    {
        // Menos de un año: la escala del primer año, y menos de tres meses
        // no genera cesantía.
        if ($completedYears === 0) {
            if ($remainingMonths >= 6) {
                return ['days' => self::SEVERANCE_FIRST_YEAR[6], 'detail' => 'de 6 meses a 1 año de servicio'];
            }

            if ($remainingMonths >= 3) {
                return ['days' => self::SEVERANCE_FIRST_YEAR[3], 'detail' => 'de 3 a 6 meses de servicio'];
            }

            return ['days' => '0.0000', 'detail' => 'menos de 3 meses de servicio: no genera cesantía'];
        }

        // La fracción mayor a seis meses cuenta como un año más.
        $years = $completedYears + ($remainingMonths >= 6 ? 1 : 0);
        $capped = min($years, self::SEVERANCE_YEAR_CAP);

        $days = '0.0000';

        for ($year = 1; $year <= $capped; $year++) {
            $days = bcadd($days, self::SEVERANCE_DAYS_BY_YEAR[min($year, 13)], 4);
        }

        $detail = $years > self::SEVERANCE_YEAR_CAP
            ? "{$years} años de servicio, topados en los ".self::SEVERANCE_YEAR_CAP.' que fija el art. 29'
            : "{$capped} año(s) según la escala del art. 29".
              ($remainingMonths >= 6 ? ' (la fracción de más de 6 meses cuenta como año completo)' : '');

        return ['days' => $days, 'detail' => $detail];
    }

    /**
     * Días de preaviso según antigüedad (CT art. 28).
     *
     * @return array{days: string, detail: string}
     */
    public static function noticeDaysFor(int $totalMonths): array
    {
        $applicable = null;

        foreach (self::NOTICE_SCALE as $fromMonths => $step) {
            if ($totalMonths >= $fromMonths) {
                $applicable = $step;
            }
        }

        return $applicable === null
            ? ['days' => '0.0000', 'detail' => 'menos de 3 meses de servicio: no genera preaviso']
            : ['days' => $applicable['days'], 'detail' => $applicable['label']];
    }
}
