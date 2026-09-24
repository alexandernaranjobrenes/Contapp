<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Payroll\DataTransferObjects\PayrollInputLine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento digitado para un período. Ver el encabezado de la migración
 * sobre por qué se guarda y qué NO entra acá.
 */
class PayrollInput extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'payroll_period_id', 'employee_id', 'payroll_concept_id',
        'amount', 'quantity', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'quantity' => 'decimal:4',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(PayrollConcept::class, 'payroll_concept_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * La forma que el motor entiende. El modelo es cómo se guarda; el DTO es
     * cómo se calcula, y mantenerlos separados deja que el motor se pruebe
     * sin tocar la base de datos.
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
