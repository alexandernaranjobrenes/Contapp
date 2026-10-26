<?php

namespace App\Domains\Pricing\Models;

use App\Domains\Licensing\Models\Propietario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un correo que el backoffice le respondió a quien pidió una licencia. Solo
 * se agregan: es el historial de lo que se le dijo. Uno que no salió queda
 * con status «failed» y el motivo.
 */
class LicenseRequestReply extends Model
{
    protected $fillable = ['license_request_id', 'propietario_id', 'to_email', 'subject', 'body', 'include_quote', 'status', 'error'];

    protected function casts(): array
    {
        return ['include_quote' => 'boolean'];
    }

    public function licenseRequest(): BelongsTo
    {
        return $this->belongsTo(LicenseRequest::class);
    }

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class);
    }
}
