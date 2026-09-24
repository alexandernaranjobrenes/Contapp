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
 * Una obligación recurrente del trabajador: adelanto, préstamo, ahorro o
 * cuota solidarista, pensión alimentaria, embargo. Ver el encabezado de la
 * migración sobre por qué son una sola tabla.
 */
class EmployeeDeduction extends Model
{
    use BelongsToCompany, HasFactory;

    public const TYPES = [
        'advance' => 'Adelanto de salario',
        'loan' => 'Préstamo',
        'solidarista_savings' => 'Ahorro solidarista',
        'solidarista_fee' => 'Cuota solidarista',
        'alimony' => 'Pensión alimentaria',
        'garnishment' => 'Embargo',
        'other' => 'Otra deducción',
    ];

    /**
     * Las que se extinguen: llevan saldo y dejan de rebajarse al llegar a
     * cero. Las demás (ahorro, cuota, pensión) siguen indefinidamente.
     */
    public const SETTLING_TYPES = ['advance', 'loan'];

    /**
     * Prioridades sugeridas. Lo que tiene preferencia legal va primero: una
     * pensión alimentaria se rebaja antes que un préstamo de consumo, y esa
     * jerarquía no puede depender del orden en que se digitaron.
     */
    public const DEFAULT_PRIORITY = [
        'alimony' => 10,
        'garnishment' => 20,
        'advance' => 30,
        'solidarista_fee' => 40,
        'solidarista_savings' => 50,
        'loan' => 60,
        'other' => 100,
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'type', 'reference', 'description',
        'payroll_concept_id', 'start_date', 'end_date',
        'original_amount', 'balance', 'calculation',
        'installment_amount', 'installment_percentage', 'priority',
        'account_id', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'original_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'installment_percentage' => 'decimal:4',
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

    public function concept(): BelongsTo
    {
        return $this->belongsTo(PayrollConcept::class, 'payroll_concept_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(EmployeeDeductionApplication::class);
    }

    /**
     * Vigente EN una fecha: activa, ya empezada y no vencida. Igual criterio
     * que las tasas — una planilla de marzo aplica lo que regía en marzo.
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('status', 'active')
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date));
    }

    /**
     * Cuánto rebajar este período, antes de topes.
     *
     * Nunca más que el saldo: la última cuota de un préstamo casi nunca es
     * igual a las anteriores, y cobrar la cuota completa dejaría el saldo en
     * negativo — es decir, le cobraría de más al trabajador.
     */
    public function installmentFor(string $periodGross): string
    {
        $amount = $this->calculation === 'percentage'
            ? bcdiv(bcmul($periodGross, (string) ($this->installment_percentage ?? '0'), 6), '100', 2)
            : number_format((float) ($this->installment_amount ?? 0), 2, '.', '');

        if ($this->balance !== null && bccomp($amount, (string) $this->balance, 2) > 0) {
            return number_format((float) $this->balance, 2, '.', '');
        }

        return $amount;
    }

    public function settles(): bool
    {
        return $this->balance !== null;
    }
}
