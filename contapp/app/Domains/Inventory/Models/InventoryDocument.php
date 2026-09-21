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
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryDocument extends Model
{
    use BelongsToCompany, HasFactory;

    public const OPERATIONS = [
        'goods_receipt' => 'Ajuste de entrada de mercancía',
        'purchase_receipt' => 'Entrada por compra (pendiente de facturar)',
        'goods_issue' => 'Salida de mercancía',
        'count_adjustment' => 'Ajuste por conteo físico',
        'production_issue' => 'Emisión a producción',
        'production_receipt' => 'Recibo de producción',
        'sales_issue' => 'Salida por venta',
        'sales_return' => 'Devolución de cliente',
        'transfer' => 'Traslado entre almacenes',
        'purchase_return' => 'Devolución al proveedor',
        'purchase_receipt_void' => 'Anulación de entrada por compra',
    ];

    /**
     * Operaciones que acumulan o descargan costo en una orden de fabricación.
     */
    public const PRODUCTION_OPERATIONS = ['production_issue', 'production_receipt'];

    /**
     * Operaciones que usan la cuenta puente GR/IR como bisagra entre el
     * movimiento de stock y su documento comercial: la recepción la acredita y
     * la factura la liquida; la devolución la debita y la nota de crédito la
     * liquida. En ambas el proveedor es obligatorio.
     */
    public const PURCHASE_OPERATIONS = ['purchase_receipt', 'purchase_return', 'purchase_receipt_void'];

    /**
     * Operaciones que se pueden anular con void(): la entrada por compra
     * mientras nadie la haya facturado. El resto del ciclo no se anula, se
     * corrige con su documento espejo —la factura con una nota de crédito,
     * la devolución con una nueva entrada—, que es lo que deja rastro.
     */
    public const VOIDABLE_OPERATIONS = ['purchase_receipt'];

    /**
     * Aduanas de Costa Rica. Es una lista y no una tabla porque no lleva
     * configuración propia: identifica por dónde entró la mercancía y nada
     * más. Si alguna vez hace falta el código numérico oficial de la DGA,
     * se agrega como valor sin tocar quién la usa.
     */
    public const CUSTOMS_OFFICES = [
        'central' => 'Central',
        'santamaria' => 'Aeropuerto Juan Santamaría',
        'caldera' => 'Caldera',
        'limon' => 'Limón',
        'penas_blancas' => 'Peñas Blancas',
        'paso_canoas' => 'Paso Canoas',
        'sixaola' => 'Sixaola',
        'golfito' => 'Golfito',
        'la_anexion' => 'La Anexión (Liberia)',
        'los_chiles' => 'Los Chiles',
    ];

    /**
     * Las únicas operaciones que se capturan a mano. El resto nace de un
     * documento previo (producción, venta, traslado, devolución) y debe pasar
     * por su servicio para quedar enlazado: una devolución suelta, por
     * ejemplo, debitaría la cuenta puente sin recepción que la cierre.
     */
    public const MANUAL_OPERATIONS = ['goods_receipt', 'purchase_receipt', 'goods_issue', 'count_adjustment'];

    protected $fillable = [
        'company_id', 'document_type_id', 'journal_entry_id', 'invoice_journal_entry_id',
        'operation', 'business_partner_id', 'production_order_id', 'source_document_id', 'purchase_order_id',
        'is_import', 'customs_declaration', 'customs_office', 'transport_document',
        'origin_country', 'customs_date',
        'document_date', 'posting_date',
        'description', 'status', 'reversal_of_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'posting_date' => 'date',
            'customs_date' => 'date',
            'is_import' => 'boolean',
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

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'source_document_id');
    }

    /**
     * Devoluciones ya registradas contra esta recepción. Es lo que limita
     * cuánto queda por devolver: sin ese tope se podría devolver más de lo
     * que entró.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(InventoryDocument::class, 'source_document_id')
            ->where('operation', 'purchase_return')
            ->where('status', 'posted');
    }

    /**
     * El documento de anulación que dejó sin efecto a este, si existe. Es
     * hasOne y no hasMany porque void() marca el original como 'voided' en la
     * misma transacción: no se puede anular dos veces.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(InventoryDocument::class, 'reversal_of_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'reversal_of_id');
    }

    public function landedCostDocuments(): HasMany
    {
        return $this->hasMany(LandedCostDocument::class, 'inventory_document_id');
    }

    /**
     * Una entrada por compra se puede anular mientras nadie la haya
     * facturado: hasta ese momento lo único que existe es el pasivo
     * provisional de la cuenta puente, que la anulación revierte entero.
     * Después ya hay una deuda real con el proveedor, y deshacerla es una
     * nota de crédito, no una anulación.
     */
    public function isVoidable(): bool
    {
        return in_array($this->operation, self::VOIDABLE_OPERATIONS, true)
            && $this->status === 'posted'
            && $this->invoice_journal_entry_id === null;
    }

    /**
     * Entradas marcadas como importación: las únicas que admiten costos de
     * nacionalización. Es la bandeja del proceso de costeo.
     */
    public function scopeImports(Builder $query): Builder
    {
        return $query->where('is_import', true)->where('status', 'posted');
    }

    /** Etiqueta legible de la aduana, o el valor crudo si no está en la lista. */
    public function customsOfficeLabel(): ?string
    {
        if ($this->customs_office === null) {
            return null;
        }

        return self::CUSTOMS_OFFICES[$this->customs_office] ?? $this->customs_office;
    }

    public function scopePendingInvoice(Builder $query): Builder
    {
        return $query->where('operation', 'purchase_receipt')
            ->where('status', 'posted')
            ->whereNull('invoice_journal_entry_id');
    }
}
