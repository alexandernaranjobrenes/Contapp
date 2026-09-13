<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTypePrinting extends Model
{
    protected $fillable = [
        'document_type_id', 'workstation', 'assigned_printer', 'print_on_save',
        'enable_direct_print', 'confirm_on_reprint', 'resume_lines_on_print', 'print_format_file',
    ];

    protected function casts(): array
    {
        return [
            'print_on_save' => 'boolean',
            'enable_direct_print' => 'boolean',
            'confirm_on_reprint' => 'boolean',
            'resume_lines_on_print' => 'boolean',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
