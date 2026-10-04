<?php

namespace App\Domains\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El nivel de una cuenta en una pantalla del menú, en una compañía
 * (ScreenCatalog, ScreenAccessService). Sin fila, sin acceso.
 *
 * Sin CompanyScope a propósito: los permisos se leen y se escriben desde
 * servicios que reciben la compañía (como ModulePermission con
 * withoutGlobalScope), no de la compañía activa.
 */
class ScreenPermission extends Model
{
    protected $fillable = ['company_id', 'user_id', 'screen', 'access_level'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
