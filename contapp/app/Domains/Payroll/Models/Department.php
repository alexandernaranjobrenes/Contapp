<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un departamento. Ver el encabezado de la migración sobre por qué dejó de
 * ser texto libre.
 */
class Department extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'cost_center_id', 'status'];

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(JobPosition::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
