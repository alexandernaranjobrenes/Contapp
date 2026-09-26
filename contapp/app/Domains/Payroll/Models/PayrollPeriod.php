<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un período de planilla y su estado.
 *
 *   abierto → calculado → aprobado → contabilizado → cerrado
 *
 * Recalcular solo se puede mientras está abierto o calculado. Una vez
 * contabilizado hay un asiento que lo respalda, y modificarlo por detrás
 * dejaría la contabilidad diciendo una cosa y la planilla otra.
 */
class PayrollPeriod extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUSES = [
        'open' => 'Abierto',
        'calculated' => 'Calculado',
        'approved' => 'Aprobado',
        'posted' => 'Contabilizado',
        'closed' => 'Cerrado',
    ];

    public const FREQUENCIES = [
        'quincenal' => 'Quincenal',
        'mensual' => 'Mensual',
        'semanal' => 'Semanal',
    ];

    /** Estados en los que todavía se puede volver a calcular. */
    public const RECALCULABLE = ['open', 'calculated'];

    protected $fillable = [
        'company_id', 'year', 'frequency', 'number', 'name',
        'start_date', 'end_date', 'payment_date', 'status',
        'journal_entry_id', 'document_type_id',
        'calculated_at', 'approved_at', 'approved_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'payment_date' => 'date',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function isRecalculable(): bool
    {
        return in_array($this->status, self::RECALCULABLE, true);
    }

    /**
     * Las frecuencias que se cuentan con el mes convencional de 30 días.
     *
     * Un salario mensual está definido POR MES, y en planilla el mes son 30
     * días con dos quincenas idénticas de 15 — sin importar que agosto tenga
     * 31 y febrero 28. La semanal no entra acá: una semana son siete días
     * reales y forzarla a la convención la distorsionaría.
     */
    public const CONVENTIONAL_MONTH_FREQUENCIES = ['mensual', 'quincenal'];

    /**
     * El día del mes que le corresponde a una fecha en la convención de 30.
     *
     * El ÚLTIMO día del mes siempre cuenta como 30. De ahí salen las dos
     * propiedades que se esperan de una planilla:
     *
     *   · el 31 de agosto no agrega un día que el mes de 30 no tiene, así
     *     que la segunda quincena trabajada completa paga 15 y no 16;
     *   · el 28 de febrero llega igual al final del mes, así que esa misma
     *     quincena paga 15 y no 13.
     *
     * Sin esta regla, trabajar la segunda quincena completa pagaría distinto
     * según el mes, que es justo lo que la convención existe para evitar.
     */
    public static function conventionalDay(\DateTimeInterface $date): int
    {
        $carbon = Carbon::instance(
            $date instanceof \DateTimeImmutable ? \DateTime::createFromImmutable($date) : $date
        );

        if ($carbon->day === $carbon->daysInMonth) {
            return 30;
        }

        return min($carbon->day, 30);
    }

    /**
     * Cuántos días cuenta el período. Es el divisor de la parte proporcional
     * cuando alguien ingresa o sale a mitad de período.
     */
    public function dayCount(): int
    {
        if (! in_array($this->frequency, self::CONVENTIONAL_MONTH_FREQUENCIES, true)) {
            return (int) $this->start_date->diffInDays($this->end_date) + 1;
        }

        return self::conventionalDay($this->end_date) - self::conventionalDay($this->start_date) + 1;
    }

    /** Si este período se cuenta con el mes convencional de 30 días. */
    public function usesConventionalMonth(): bool
    {
        return in_array($this->frequency, self::CONVENTIONAL_MONTH_FREQUENCIES, true);
    }
}
