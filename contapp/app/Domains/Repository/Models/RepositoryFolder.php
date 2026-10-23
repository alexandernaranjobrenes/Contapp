<?php

namespace App\Domains\Repository\Models;

use App\Domains\Licensing\Models\Propietario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una carpeta del Repositorio («Leyes», «Resoluciones DGT»…). La arma el
 * backoffice, y las cuentas ven los documentos agrupados igual. Global, sin
 * compañía. Con documentos adentro no se puede eliminar.
 */
class RepositoryFolder extends Model
{
    public const MAX_NAME = 100;

    public const MAX_DESCRIPTION = 500;

    protected $fillable = ['name', 'description', 'created_by', 'updated_by'];

    public function documents(): HasMany
    {
        return $this->hasMany(RepositoryDocument::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'created_by');
    }
}
