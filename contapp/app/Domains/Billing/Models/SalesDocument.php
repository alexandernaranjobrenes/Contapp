<?php

namespace App\Domains\Billing\Models;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Inventory\Models\InventoryDocument;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDocument extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'document_type_id', 'fiscal_document_type', 'situation',
        'branch', 'terminal', 'consecutive', 'clave', 'security_code',
        'emitter_activity_code', 'receiver_activity_code', 'business_partner_id',
        'currency_id', 'exchange_rate', 'sale_condition', 'credit_term_days',
        'document_date', 'posting_date', 'due_date',
        'total_taxed_services', 'total_exempt_services', 'total_exonerated_services', 'total_no_subject_services',
        'total_taxed_goods', 'total_exempt_goods', 'total_exonerated_goods', 'total_no_subject_goods',
        'total_sale', 'total_discounts', 'total_net_sale', 'total_tax', 'total_document',
        'notes', 'status', 'journal_entry_id', 'inventory_document_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'posting_date' => 'date',
            'due_date' => 'date',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentLine::class)->orderBy('line_number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesPayment::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(SalesReference::class);
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function inventoryDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class);
    }
}
