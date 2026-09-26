<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una acción de personal: el hecho con fecha de vigencia que explica por qué
 * la ficha del trabajador dice hoy lo que dice.
 */
class PersonnelAction extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * Qué campo de la ficha toca cada tipo de acción. Es lo que permite
     * aplicarla sin un `match` repetido en cada lugar que la use.
     */
    public const FIELD_BY_TYPE = [
        'salary_change' => 'base_salary',
        'position_change' => 'position',
        'cost_center_change' => 'cost_center_id',
        'journey_change' => 'journey_type',
    ];

    public const TYPES = [
        'hire' => 'Contratación',
        'salary_change' => 'Cambio de salario',
        'position_change' => 'Cambio de puesto',
        'cost_center_change' => 'Cambio de centro de costo',
        'journey_change' => 'Cambio de jornada',
        'vacation' => 'Vacaciones',
        'warning' => 'Amonestación',
        'suspension' => 'Suspensión',
        'reinstatement' => 'Reincorporación',
        'termination' => 'Terminación',
    ];

    /**
     * Las que no cambian ningún campo de la ficha: son hechos que se
     * registran y se aprueban, no modificaciones.
     *
     * Una amonestación y unas vacaciones aprobadas tienen que quedar en el
     * historial con su fecha y su firma —de eso depende un despido con causa
     * o un reclamo por días no disfrutados— pero no tocan la ficha: el saldo
     * de vacaciones lo llevan sus propios movimientos.
     */
    public const RECORD_ONLY = ['vacation', 'warning'];

    public const STATUSES = [
        'draft' => 'Borrador',
        'approved' => 'Aprobada',
        'applied' => 'Aplicada',
        'cancelled' => 'Anulada',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'action_type', 'effective_date',
        'previous_value', 'new_value', 'field', 'reason', 'status',
        'requested_by', 'approved_by', 'approved_at', 'applied_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
