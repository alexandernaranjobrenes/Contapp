<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\CostCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * La boleta de un empleado en un período: su comprobante de pago.
 *
 * No lleva company_id: cuelga del período, que sí lo tiene, igual que las
 * líneas de cualquier documento del sistema.
 *
 * Los totales se guardan calculados y no se derivan de las líneas al
 * consultarlos, por la misma razón que el detalle se congela: una boleta es
 * lo que el trabajador recibió ese día, y tiene que poder reimprimirse
 * idéntica años después.
 */
class PayrollEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_period_id', 'employee_id', 'cost_center_id',
        'days_worked', 'base_salary',
        'total_earnings', 'ccss_base', 'income_tax_base',
        'total_employee_contributions', 'income_tax', 'total_other_deductions',
        'total_deductions', 'net_pay',
        'total_employer_contributions', 'total_provisions',
        'payment_method', 'bank_account', 'notes',
    ];

    /**
     * Los montos se leen como cadenas decimales, no como float: toda la
     * aritmética del motor es bcmath, y un float que entra en medio
     * reintroduce el error de redondeo que bcmath existe para evitar.
     */
    protected function casts(): array
    {
        return [
            'days_worked' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'total_earnings' => 'decimal:2',
            'ccss_base' => 'decimal:2',
            'income_tax_base' => 'decimal:2',
            'total_employee_contributions' => 'decimal:2',
            'income_tax' => 'decimal:2',
            'total_other_deductions' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'total_employer_contributions' => 'decimal:2',
            'total_provisions' => 'decimal:2',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }

    /**
     * Lo que de verdad le cuesta este trabajador a la empresa: su salario
     * bruto, más lo que el patrono aporta encima, más lo que se provisiona.
     *
     * Es el número que casi nunca se ve y el único que sirve para costear un
     * puesto o cotizar un proyecto: el salario bruto solo subestima el costo
     * real en una proporción grande.
     */
    public function employerCost(): string
    {
        return bcadd(
            bcadd((string) $this->total_earnings, (string) $this->total_employer_contributions, 2),
            (string) $this->total_provisions,
            2
        );
    }

    /**
     * @return Collection<int, PayrollEntryLine>
     */
    public function linesOfKind(string $kind)
    {
        return $this->lines->where('kind', $kind)->values();
    }
}
