<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Crédito familiar: monto fijo mensual que se resta del impuesto YA
 * calculado, no de la base. Restarlo de la base daría un alivio menor y
 * distinto del que la ley concede.
 */
class PayrollTaxCredit extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'monthly_amount', 'valid_from', 'valid_to',
    ];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
