<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Las cuentas y parámetros de la planilla de una compañía. Una sola fila.
 */
class PayrollSetting extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'salary_expense_account_id', 'net_payable_account_id', 'income_tax_payable_account_id',
        'document_type_id', 'vacation_days_per_month', 'vacation_average_basis',
        'max_deduction_percentage', 'income_tax_mode', 'income_tax_base',
    ];

    /**
     * Con qué promedio se pagan las vacaciones en una liquidación. Ver el
     * encabezado de la migración que agregó el parámetro: las dos ventanas
     * se usan en el país y dan números distintos.
     */
    public const VACATION_AVERAGE_BASES = [
        'practice' => 'Práctica: 2 semanas si el pago es semanal, 6 meses si es quincenal o mensual',
        'legal_50_weeks' => 'Literal del art. 157: promedio de las últimas 50 semanas',
    ];

    /** Cuántas semanas abarca cada criterio para un trabajador dado. */
    public const VACATION_AVERAGE_WEEKS = [
        'practice_weekly' => 2,
        'legal_50_weeks' => 50,
    ];

    /**
     * Cómo se llega al mes cuando el período no es mensual. Ver el encabezado
     * de incomeTaxFor() en CalculatePayrollService.
     */
    public const INCOME_TAX_MODES = [
        'accumulated' => 'Acumulado del mes (suma los períodos anteriores)',
        'projected' => 'Proyectado (multiplica la quincena por dos)',
    ];

    /** Sobre qué se aplica la escala. Es una cuestión de la ley, no del motor. */
    public const INCOME_TAX_BASES = [
        'gross' => 'Salario devengado gravable',
        'net_of_contributions' => 'Devengado gravable menos cargas obreras',
    ];

    public function salaryExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'salary_expense_account_id');
    }

    public function netPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'net_payable_account_id');
    }

    public function incomeTaxPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_tax_payable_account_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
