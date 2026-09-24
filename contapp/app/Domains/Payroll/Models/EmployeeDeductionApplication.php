<?php

namespace App\Domains\Payroll\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rebajo concreto contra una obligación: el estado de cuenta del
 * préstamo. Sin estas filas, `balance` sería un número sin historia.
 */
class EmployeeDeductionApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_deduction_id', 'payroll_entry_id', 'applied_on', 'amount', 'balance_after',
    ];

    protected function casts(): array
    {
        return ['applied_on' => 'date'];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class, 'employee_deduction_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }
}
