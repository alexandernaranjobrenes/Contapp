<?php

namespace App\Domains\Core\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Numeración manual "por series", independiente del consecutivo interno
 * automático e inalterable de DocumentType (document_types.next_consecutive,
 * usado por PostJournalService::nextDocumentNumber()). Una serie es un rango
 * [range_from, range_to] configurado a mano, con su propio contador
 * (next_number) — un tipo de documento puede tener varias series activas a
 * la vez (ej. "Caja 1", "Caja 2"), a diferencia del consecutivo automático,
 * que es único por tipo de documento. holder_name identifica a quién se le
 * entregó la serie (ej. un talonario físico de recibos numerado), para
 * poder saber desde el propio asiento contabilizado quién cobró.
 */
class DocumentTypeNumberSeries extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'document_type_id', 'name', 'holder_name', 'range_from', 'range_to', 'next_number', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function isExhausted(): bool
    {
        return $this->next_number > $this->range_to;
    }
}
