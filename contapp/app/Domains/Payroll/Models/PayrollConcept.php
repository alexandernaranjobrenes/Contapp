<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un concepto de ingreso o deducción.
 *
 * Los tres indicadores affects_* son el corazón del cálculo costarricense:
 * deciden si el concepto forma salario para cargas sociales, si entra a la
 * base del impuesto y si cuenta para aguinaldo, vacaciones y cesantía. Ver
 * el encabezado de la migración.
 */
class PayrollConcept extends Model
{
    use BelongsToCompany, HasFactory;

    public const TYPES = [
        'earning' => 'Ingreso',
        'deduction' => 'Deducción',
    ];

    public const CALCULATIONS = [
        'amount' => 'Monto digitado',
        'percentage' => 'Porcentaje del salario base',
        'hours' => 'Cantidad de horas por valor hora por factor',
    ];

    protected $fillable = [
        'company_id', 'code', 'name', 'type',
        'affects_ccss', 'affects_income_tax', 'affects_provisions',
        'calculation', 'factor', 'account_id', 'is_recurring', 'status', 'legal_basis',
    ];

    protected function casts(): array
    {
        return [
            'affects_ccss' => 'boolean',
            'affects_income_tax' => 'boolean',
            'affects_provisions' => 'boolean',
            'is_recurring' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /** Las líneas de boleta que ya la aplicaron. Que existan impide borrarla. */
    public function entryLines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class, 'payroll_concept_id');
    }
}
