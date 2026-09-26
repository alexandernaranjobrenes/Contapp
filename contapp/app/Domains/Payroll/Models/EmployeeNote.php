<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una anotación en la bitácora del funcionario. Ver el encabezado de la
 * migración sobre por qué no alcanza el campo `notas` de la ficha.
 */
class EmployeeNote extends Model
{
    use BelongsToCompany, HasFactory;

    public const CATEGORIES = [
        'observation' => 'Observación',
        'recognition' => 'Reconocimiento',
        'warning' => 'Llamada de atención',
        'incident' => 'Incidente',
        'meeting' => 'Reunión',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'happened_on', 'category',
        'title', 'body', 'is_confidential', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'happened_on' => 'date',
            'is_confidential' => 'boolean',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
