<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envío o una consulta a Hacienda, tal como fue. Solo se agregan. Nunca
 * guarda el token ni el XML enviado (ese está, una sola vez, en el documento).
 */
class ElectronicDocumentExchange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'electronic_document_id', 'operation', 'http_status', 'ind_estado', 'error_cause',
        'response_headers', 'response_body', 'response_xml', 'duration_ms', 'created_at',
    ];

    protected $hidden = ['response_xml'];

    protected function casts(): array
    {
        return [
            'response_headers' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }
}
