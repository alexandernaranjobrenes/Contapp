<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la liquidación, con sus días y su tarifa congelados.
 *
 * El detalle (`detail`) no es decorativo: es la explicación de dónde salió el
 * número, y es lo que el trabajador lee y firma. «22 días × ₡23.333,33» se
 * revisa; «₡513.333,26» solo se acepta o se pelea.
 */
class LaborSettlementLine extends Model
{
    use HasFactory;

    /**
     * Los extremos liquidables, en el orden en que se presentan.
     *
     * El orden importa: es el de cualquier liquidación costarricense y el que
     * espera quien la revisa.
     */
    public const KINDS = [
        'pending_salary' => 'Salarios pendientes',
        'christmas_bonus' => 'Aguinaldo proporcional',
        'vacation' => 'Vacaciones',
        'notice' => 'Preaviso',
        'severance' => 'Auxilio de cesantía',
        'indemnity' => 'Indemnización',
        'deduction' => 'Deducción',
    ];

    /**
     * Qué extremos tienen naturaleza salarial y por tanto cotizan.
     *
     * Solo dos. La cesantía y el preaviso son indemnizatorios, y el aguinaldo
     * tiene exención expresa (Ley 6949 y Ley del Impuesto sobre la Renta).
     * Rebajarle cargas a la cesantía le quita al trabajador un 10,83% que la
     * ley no permite rebajar, y es uno de los errores más comunes en una
     * liquidación hecha a mano.
     */
    public const SALARY_NATURE_KINDS = ['pending_salary', 'vacation'];

    protected $fillable = [
        'labor_settlement_id', 'line_number', 'kind', 'code', 'name', 'detail',
        'days', 'daily_rate', 'amount',
        'subject_to_ccss', 'subject_to_income_tax', 'account_id',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'decimal:4',
            'daily_rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'subject_to_ccss' => 'boolean',
            'subject_to_income_tax' => 'boolean',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(LaborSettlement::class, 'labor_settlement_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** Si el renglón resta en vez de sumar. */
    public function isDeduction(): bool
    {
        return $this->kind === 'deduction';
    }
}
