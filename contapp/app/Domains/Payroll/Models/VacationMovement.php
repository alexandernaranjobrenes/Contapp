<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento del saldo de vacaciones. El saldo es SUM(days): positivo
 * acredita, negativo rebaja. Ver el encabezado de la migración.
 */
class VacationMovement extends Model
{
    use BelongsToCompany, HasFactory;

    public const TYPES = [
        'accrual' => 'Acreditación',
        'taken' => 'Disfrute',
        'paid' => 'Pago en efectivo',
        'adjustment' => 'Ajuste',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'type', 'movement_date', 'days',
        'from_date', 'to_date', 'payroll_period_id', 'payroll_entry_id',
        'amount', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'from_date' => 'date',
            'to_date' => 'date',
            // Cuatro decimales: una quincena acredita una fracción de día, y
            // sin el cast el valor vuelve con la precisión que le dé el
            // driver en vez de la de la columna.
            'days' => 'decimal:4',
            'amount' => 'decimal:2',
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

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }
}
