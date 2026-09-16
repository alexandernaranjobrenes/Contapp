<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_document_id', 'document_type', 'number', 'issued_at', 'reason_code', 'reason',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'date'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }
}
