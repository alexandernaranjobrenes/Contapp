<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
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
     * Cuántos días calendario cubre el período. Es el divisor de la parte
     * proporcional cuando alguien ingresa o sale a mitad de período.
     */
    public function dayCount(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }
}
