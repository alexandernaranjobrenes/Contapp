<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un avalúo de deterioro (NIC 2 §28): la comparación entre el costo del
 * inventario y su valor neto realizable a una fecha, con el asiento que
 * ajusta la estimación.
 */
class InventoryWriteDown extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'document_type_id', 'journal_entry_id', 'as_of',
        'description', 'status', 'reversal_of_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryWriteDownLine::class)->orderBy('line_number');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
