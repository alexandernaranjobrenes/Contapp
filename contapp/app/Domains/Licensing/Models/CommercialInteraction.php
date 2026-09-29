<?php

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial append-only (CLAUDE.md secc. 15: "se agregan interacciones, no
 * se editan retroactivamente") — a propósito no hay update()/destroy() en
 * CommercialInteractionController, ni rutas para ellos: la bitácora comercial
 * tiene que quedar confiable, no reescribible.
 */
class CommercialInteraction extends Model
{
    public const TYPES = [
        'call' => 'Llamada',
        'email' => 'Correo',
        'meeting' => 'Reunión',
        'whatsapp' => 'WhatsApp',
        'other' => 'Otro',
    ];

    protected $fillable = ['commercial_profile_id', 'type', 'occurred_at', 'author_id', 'summary'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date',
        ];
    }

    /**
     * occurred_at se muestra tal cual en el perfil comercial: sin esto
     * saldría con hora y zona. Mismo criterio que License::serializeDate().
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function commercialProfile(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Propietario::class, 'author_id');
    }
}
