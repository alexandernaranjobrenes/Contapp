<?php

namespace App\Domains\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de estado de un comprobante electrónico. Solo se agregan: nadie
 * edita ni borra la historia de lo que pasó con un comprobante fiscal.
 */
class ElectronicDocumentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['electronic_document_id', 'from_status', 'to_status', 'message', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }
}
