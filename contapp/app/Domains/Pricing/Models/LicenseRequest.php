<?php

namespace App\Domains\Pricing\Models;

use App\Domains\Licensing\Models\Propietario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una solicitud de licencia desde /precios (CLAUDE.md secc. 42).
 *
 * - quote es la cotización tal como la vio quien la pidió (la calculó el
 *   servidor, no el navegador): un cambio de precios después no la toca.
 * - status: «new» hasta que el backoffice la responde por correo
 *   («replied») o la descarta sin responder («discarded»). Se puede
 *   reabrir.
 */
class LicenseRequest extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'status', 'contact_name', 'company_name', 'identification', 'email', 'phone', 'message', 'package_id',
        'package_name', 'billing_cycle', 'currency', 'quote', 'total', 'consented_at', 'ip_address', 'user_agent',
        'internal_note', 'handled_by', 'handled_at',
    ];

    protected function casts(): array
    {
        return [
            'quote' => 'array',
            'total' => 'decimal:2',
            'consented_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PricingPackage::class, 'package_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'handled_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(LicenseRequestReply::class)->orderBy('id');
    }

    /** «SOL-000012»: cómo se nombra en los correos y en el backoffice. */
    public function reference(): string
    {
        return 'SOL-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
