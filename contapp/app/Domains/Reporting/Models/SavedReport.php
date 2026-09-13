<?php

namespace App\Domains\Reporting\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una combinación de parámetros guardada para uno de los reportes existentes
 * (report_code, ver App\Domains\Reporting\Support\ReportCatalog), lista para
 * reinvocarse sin volver a configurar filtros. Nunca guarda company_id
 * dentro de "parameters": la compañía activa siempre se resuelve de la
 * sesión al invocar (ver SavedReportService).
 */
class SavedReport extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['report_code', 'name', 'parameters', 'is_shared'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'is_shared' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Míos + compartidos por cualquiera de mi compañía — el aislamiento por
     * compañía ya lo aplica el global scope de BelongsToCompany; esto solo
     * agrega el filtro mío-vs-compartido encima.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('user_id', $user->id)
            ->orWhere('is_shared', true)
        );
    }
}
