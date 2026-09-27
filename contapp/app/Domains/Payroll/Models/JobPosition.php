<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un puesto, con su código de ocupación de la CCSS y su rango salarial de
 * referencia. Ver el encabezado de la migración.
 */
class JobPosition extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'department_id',
        'ccss_occupation_code', 'ccss_occupation_name',
        'min_salary', 'max_salary', 'description', 'status',
    ];

    protected function casts(): array
    {
        return [
            'min_salary' => 'decimal:2',
            'max_salary' => 'decimal:2',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Si un salario se sale del rango previsto para el puesto.
     *
     * No es un error —hay excepciones legítimas— pero sí algo que conviene
     * mirar: un cero de más en un aumento no lo detecta nadie leyendo la
     * ficha, y sí lo detecta comparar contra el rango.
     */
    public function salaryOutOfRange(string $salary): bool
    {
        if ($this->min_salary !== null && bccomp($salary, (string) $this->min_salary, 2) < 0) {
            return true;
        }

        return $this->max_salary !== null && bccomp($salary, (string) $this->max_salary, 2) > 0;
    }
}
