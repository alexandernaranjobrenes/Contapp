<?php

namespace App\Domains\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No lleva company_id propio: se filtra a través de fiscal_year_id -> fiscal_years.company_id.
 * No usa BelongsToCompany porque no tiene la columna; el aislamiento entre
 * compañías lo garantiza el scope de FiscalYear del que siempre cuelga.
 */
class FiscalPeriod extends Model
{
    use HasFactory;

    protected $fillable = ['fiscal_year_id', 'period_number', 'start_date', 'end_date', 'status'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function containsDate(\DateTimeInterface $date): bool
    {
        return $date >= $this->start_date && $date <= $this->end_date;
    }
}
