<?php

namespace App\Domains\Tax\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\GlobalOrOwnCompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Las tarifas se versionan por fecha (effective_from/to): una tarifa histórica
 * nunca se edita, se cierra su vigencia y se crea una nueva fila.
 *
 * company_id NULL = catálogo nacional compartido (ver TaxType); un
 * company_id real es una tarifa propia de esa compañía, invisible para las
 * demás (ver GlobalOrOwnCompanyScope).
 */
class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'tax_type_id', 'code', 'name', 'percentage',
        'grants_fiscal_credit', 'fiscal_credit_note',
        'effective_from', 'effective_to',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new GlobalOrOwnCompanyScope);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'grants_fiscal_credit' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * Sin esto, effective_from/to se serializan a JSON con hora y zona
     * completas — mismo hallazgo ya corregido en CostCenter/BusinessPartner/
     * ExchangeRate/JournalEntry (ver docs/decisiones.md 2026-08-16).
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class);
    }

    public function isEffectiveOn(\DateTimeInterface $date): bool
    {
        $d = $date->format('Y-m-d');

        return $this->effective_from->format('Y-m-d') <= $d
            && (! $this->effective_to || $this->effective_to->format('Y-m-d') >= $d);
    }
}
