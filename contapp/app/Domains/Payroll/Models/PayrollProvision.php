<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parámetro de provisión: aguinaldo, vacaciones, cesantía o preaviso.
 *
 * Son obligaciones que nacen con el trabajo del período aunque se paguen
 * después; registrarlas solo al pagarlas dejaría los estados financieros sin
 * un pasivo que ya existe y cargaría a un solo mes un gasto que se devengó
 * durante todo el año.
 */
class PayrollProvision extends Model
{
    use BelongsToCompany, HasFactory;

    public const CODES = [
        'aguinaldo' => 'Aguinaldo',
        'vacaciones' => 'Vacaciones',
        'cesantia' => 'Cesantía',
        'preaviso' => 'Preaviso',
    ];

    protected $fillable = [
        'company_id', 'code', 'name', 'percentage',
        'expense_account_id', 'liability_account_id',
        'valid_from', 'valid_to', 'status', 'legal_basis',
    ];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'liability_account_id');
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('status', 'active')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }

    /** Las líneas de boleta que ya la aplicaron. Que existan impide borrarla. */
    public function entryLines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class, 'payroll_provision_id');
    }
}
