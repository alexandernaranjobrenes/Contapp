<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentTypePermission extends Model
{
    protected $fillable = [
        'document_type_id', 'subject_type', 'subject_id', 'scope',
        'can_create', 'can_modify', 'can_delete', 'can_void', 'can_vary_consecutive',
        'can_backdate', 'can_modify_integrated_documents', 'can_create_out_of_range',
        'can_modify_document_dates',
    ];

    protected function casts(): array
    {
        return [
            'can_create' => 'boolean',
            'can_modify' => 'boolean',
            'can_delete' => 'boolean',
            'can_void' => 'boolean',
            'can_vary_consecutive' => 'boolean',
            'can_backdate' => 'boolean',
            'can_modify_integrated_documents' => 'boolean',
            'can_create_out_of_range' => 'boolean',
            'can_modify_document_dates' => 'boolean',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
