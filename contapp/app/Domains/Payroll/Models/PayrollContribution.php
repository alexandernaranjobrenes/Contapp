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
 * Un componente de carga social con su vigencia. Ver el encabezado de la
 * migración sobre por qué ninguna tasa vive en código.
 */
class PayrollContribution extends Model
{
    use BelongsToCompany, HasFactory;

    public const PAYERS = [
        'employee' => 'Obrero (se le rebaja al trabajador)',
        'employer' => 'Patronal (lo paga la empresa además del salario)',
    ];

    public const INSTITUTIONS = [
        'ccss' => 'CCSS',
        'banco_popular' => 'Banco Popular',
        'ins' => 'INS',
        'ina' => 'INA',
        'imas' => 'IMAS',
        'fcl' => 'Fondo de Capitalización Laboral',
        'rop' => 'Régimen Obligatorio de Pensiones',
        'otro' => 'Otra institución',
    ];

    protected $fillable = [
        'company_id', 'code', 'name', 'payer', 'institution', 'percentage',
        'base', 'ceiling_amount', 'expense_account_id', 'liability_account_id',
        'valid_from', 'valid_to', 'status', 'legal_basis',
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

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'liability_account_id');
    }

    /**
     * Vigente EN una fecha, no "vigente hoy": una planilla de marzo se
     * calcula con las tasas que regían en marzo. Es toda la razón de que
     * esta tabla tenga vigencias.
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('status', 'active')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }

    /** Las líneas de boleta que ya la aplicaron. Que existan impide borrarla. */
    public function entryLines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class, 'payroll_contribution_id');
    }
}
