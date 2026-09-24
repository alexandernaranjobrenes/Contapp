<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un tramo de la escala del impuesto al salario, con su vigencia.
 *
 * La escala es MENSUAL y progresiva: cada tramo grava solo la porción del
 * salario que cae dentro de él. Aplicar la tasa del tramo superior a todo el
 * salario —el error clásico— cobra de más y produce un salto imposible al
 * cruzar el límite.
 */
class PayrollTaxBracket extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'valid_from', 'valid_to', 'bracket_number',
        'from_amount', 'to_amount', 'percentage',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
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
