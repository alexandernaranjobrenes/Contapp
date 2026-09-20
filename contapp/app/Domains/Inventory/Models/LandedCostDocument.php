<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandedCostDocument extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'document_type_id', 'journal_entry_id', 'inventory_document_id',
        'business_partner_id', 'document_date', 'posting_date', 'amount',
        'capitalized_amount', 'expensed_amount', 'description', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'posting_date' => 'date',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * Rubros acumulados que financiaron este costeo. Vacío cuando el costo se
     * registró con la vía directa (factura del proveedor al momento).
     */
    public function importCostAllocations(): HasMany
    {
        return $this->hasMany(ImportCostAllocation::class, 'landed_cost_document_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'inventory_document_id');
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
