<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTypeVisibleColumn extends Model
{
    protected $fillable = ['document_type_id', 'column_key', 'visible', 'language'];

    protected function casts(): array
    {
        return ['visible' => 'boolean'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
