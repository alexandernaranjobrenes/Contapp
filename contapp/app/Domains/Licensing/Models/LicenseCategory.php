<?php

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de configuración (CLAUDE.md secc. 13): "Básica"/"Profesional"/
 * "Corporativa" no son valores fijos en código, son filas acá. Global, sin
 * company_id — es del Propietario, no de ningún cliente.
 */
class LicenseCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'max_companies', 'max_admins', 'max_users', 'duration_months', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class, 'category_id');
    }
}
