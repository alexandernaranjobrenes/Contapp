<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    use BelongsToCompany, HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'currency_id', 'rate_date', 'rate_type', 'rate', 'source', 'is_locked', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:6',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * Sin esto, rate_date se serializa a JSON con hora y zona
     * (ej. "2026-08-16T00:00:00.000000Z") en vez de "2026-08-16".
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
