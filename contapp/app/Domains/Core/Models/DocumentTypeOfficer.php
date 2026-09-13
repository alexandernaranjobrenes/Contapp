<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTypeOfficer extends Model
{
    protected $fillable = ['document_type_id', 'officer_role', 'required'];

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
