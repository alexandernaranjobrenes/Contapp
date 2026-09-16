<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryDocument extends Model
{
    use BelongsToCompany, HasFactory;

    public const OPERATIONS = [
        'goods_receipt' => 'Entrada de mercancía',
        'purchase_receipt' => 'Entrada por compra (pendiente de facturar)',
        'goods_issue' => 'Salida de mercancía',
        'count_adjustment' => 'Ajuste por conteo físico',
        'production_issue' => 'Emisión a producción',
        'production_receipt' => 'Recibo de producción',
        'sales_issue' => 'Salida por venta',
        'transfer' => 'Traslado entre almacenes',
    ];

    /**
     * Operaciones que acumulan o descargan costo en una orden de fabricación.
     */
    public const PRODUCTION_OPERATIONS = ['production_issue', 'production_receipt'];

    protected $fillable = [
        'company_id', 'document_type_id', 'journal_entry_id', 'invoice_journal_entry_id',
        'operation', 'business_partner_id', 'production_order_id', 'document_date', 'posting_date',
        'description', 'status', 'reversal_of_id', 'created_by',
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

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryDocumentLine::class)->orderBy('line_number');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    /**
     * El asiento de la factura de proveedor que liquidó la cuenta puente
     * GR/IR de esta recepción. Null mientras siga pendiente de facturar.
     */
    public function invoiceJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'invoice_journal_entry_id');
    }

    public function scopePendingInvoice(Builder $query): Builder
    {
        return $query->where('operation', 'purchase_receipt')
            ->where('status', 'posted')
            ->whereNull('invoice_journal_entry_id');
    }
}
