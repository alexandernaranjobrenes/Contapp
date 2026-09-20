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

/**
 * Rubro de nacionalización acumulado: la factura del transportista, de la
 * agencia aduanal o del almacén fiscal, contabilizada contra la transitoria
 * antes de saber a qué importación se le carga.
 */
class ImportCostDocument extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * Rubros típicos de una nacionalización. Es una lista y no una tabla
     * porque no lleva configuración propia: la cuenta transitoria es una sola
     * para todos, y el concepto sirve para leer y reportar.
     */
    public const CONCEPTS = [
        'flete' => 'Flete internacional',
        'seguro' => 'Seguro de la mercancía',
        'aranceles' => 'Aranceles e impuestos de importación',
        'almacenaje' => 'Almacenaje fiscal',
        'agencia' => 'Agencia aduanal',
        'transporte_interno' => 'Transporte interno',
        'otros' => 'Otros costos de importación',
    ];

    public const STATUSES = [
        'pending' => 'Por asignar',
        'partial' => 'Parcialmente asignado',
        'allocated' => 'Asignado',
        'cancelled' => 'Cancelado',
    ];

    protected $fillable = [
        'company_id', 'number', 'document_type_id', 'journal_entry_id', 'business_partner_id',
        'concept', 'document_date', 'posting_date', 'due_date', 'amount', 'allocated_amount',
        'status', 'description', 'created_by',
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

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ImportCostAllocation::class);
    }

    /** Lo que queda sin asignar a ninguna importación. */
    public function pendingAmount(): string
    {
        return bcsub((string) $this->amount, (string) $this->allocated_amount, 2);
    }

    /**
     * Rubros que todavía tienen saldo por asignar: la bandeja del proceso de
     * costeo, y el detalle de lo que la cuenta transitoria muestra en el balance.
     */
    public function scopePendingAllocation(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'partial']);
    }
}
