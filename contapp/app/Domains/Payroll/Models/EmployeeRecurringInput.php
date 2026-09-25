<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Payroll\DataTransferObjects\PayrollInputLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rubro fijo de un empleado: se aplica en cada período sin digitarlo.
 *
 * Ver el encabezado de la migración sobre por qué la recurrencia vive acá y
 * no en el concepto.
 */
class EmployeeRecurringInput extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'employee_id', 'payroll_concept_id',
        'amount', 'quantity', 'start_date', 'end_date', 'notes', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'amount' => 'decimal:2',
            'quantity' => 'decimal:4',
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

    public function concept(): BelongsTo
    {
        return $this->belongsTo(PayrollConcept::class, 'payroll_concept_id');
    }

    /**
     * Vigente EN una fecha, no «vigente hoy»: una planilla de marzo aplica lo
     * que regía en marzo. Mismo criterio que las tasas y las obligaciones.
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('status', 'active')
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date));
    }

    /**
     * La forma que el motor entiende. Un rubro fijo y uno digitado producen
     * exactamente la misma línea: el motor no tiene por qué saber de dónde
     * vino, y así no hay dos caminos de cálculo que puedan divergir.
     */
    public function toInputLine(): PayrollInputLine
    {
        return new PayrollInputLine(
            employeeId: $this->employee_id,
            conceptId: $this->payroll_concept_id,
            amount: $this->amount,
            quantity: $this->quantity,
            notes: $this->notes,
        );
    }
}
