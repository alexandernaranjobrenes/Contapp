<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la boleta, con su base y su tasa CONGELADAS.
 *
 * Guardar la tasa junto al monto es lo que permite reproducir y auditar una
 * planilla vieja sin depender de la configuración actual: si mañana la Caja
 * mueve un porcentaje, la boleta de marzo sigue mostrando el que se le
 * aplicó, y el trabajador puede verificar su propio rebajo.
 */
class PayrollEntryLine extends Model
{
    use HasFactory;

    /**
     * Las seis familias. Se separan porque se presentan y se contabilizan
     * distinto: los ingresos suman al bruto, las cargas obreras y el
     * impuesto lo rebajan, las patronales y las provisiones no tocan al
     * trabajador pero sí el costo de la empresa.
     */
    public const KINDS = [
        'earning' => 'Ingreso',
        'employee_contribution' => 'Carga social obrera',
        'income_tax' => 'Impuesto al salario',
        'deduction' => 'Otra deducción',
        'employer_contribution' => 'Carga social patronal',
        'provision' => 'Provisión',
    ];

    /** Las que rebajan el neto del trabajador. */
    public const DEDUCTION_KINDS = ['employee_contribution', 'income_tax', 'deduction'];

    /** Las que son costo de la empresa y no tocan al trabajador. */
    public const EMPLOYER_KINDS = ['employer_contribution', 'provision'];

    protected $fillable = [
        'payroll_entry_id', 'line_number', 'kind', 'code', 'name',
        'payroll_concept_id', 'payroll_contribution_id', 'payroll_provision_id',
        'base_amount', 'rate', 'quantity', 'amount', 'account_id',
    ];

    /** Cadenas decimales, no float: la aritmética del motor es bcmath. */
    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'quantity' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(PayrollConcept::class, 'payroll_concept_id');
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(PayrollContribution::class, 'payroll_contribution_id');
    }

    public function provision(): BelongsTo
    {
        return $this->belongsTo(PayrollProvision::class, 'payroll_provision_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
