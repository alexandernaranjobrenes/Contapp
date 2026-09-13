<?php

namespace App\Domains\Tax\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\GlobalOrOwnCompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * company_id NULL = catálogo nacional (el IVA es ley, no varía por
 * compañía), compartido por todo el sistema y editable solo por el
 * Propietario. Un company_id real es un tipo de impuesto que una compañía
 * creó para sí misma (ej. "Impuesto Municipal") — invisible para las demás
 * (ver GlobalOrOwnCompanyScope).
 */
class TaxType extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'code', 'name'];

    protected static function booted(): void
    {
        static::addGlobalScope(new GlobalOrOwnCompanyScope);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
