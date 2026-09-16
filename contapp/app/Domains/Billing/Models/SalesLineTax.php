<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLineTax extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_document_line_id', 'tax_code', 'iva_rate_code', 'rate_percentage',
        'taxable_base', 'amount',
        'exoneration_document_type', 'exoneration_document_number', 'exoneration_article',
        'exoneration_clause', 'exoneration_institution', 'exoneration_date',
        'exonerated_percentage', 'exonerated_amount', 'net_amount',
    ];

    protected function casts(): array
    {
        return ['exoneration_date' => 'date'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentLine::class, 'sales_document_line_id');
    }
}
