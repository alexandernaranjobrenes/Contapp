<?php

namespace App\Domains\BusinessPartners\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessPartner extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'tax_id', 'category_id', 'family_id', 'cost_center_id',
        'gl_account_id', 'currency_id', 'price_list_id', 'credit_limit', 'payment_terms_days', 'status',
        'email', 'economic_activity_code', 'phone', 'contact_name', 'partner_since',
    ];

    protected function casts(): array
    {
        return [
            'partner_since' => 'date',
        ];
    }

    /**
     * Sin esto, partner_since se serializa a JSON con hora y zona
     * (ej. "2026-08-16T00:00:00.000000Z") en vez de "2026-08-16" — se nota
     * apenas se muestra en una tabla o se precarga en el form de edición.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BpCategory::class, 'category_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(BpFamily::class, 'family_id');
    }

    /**
     * Referencia informativa para reportes/análisis por centro de costo —
     * a diferencia de journal_details.cost_center_id (que sí participa de
     * PostJournalService), esta no exige centro hoja ni vigente: es solo
     * "a qué centro pertenece este socio", no una línea de asiento.
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Nullable = usa la lista predeterminada de la compañía. Asignar una es
     * la excepción: mayorista, distribuidor, convenio.
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Inventory\Models\PriceList::class);
    }

    public function openItems(): HasMany
    {
        return $this->hasMany(BpOpenItem::class);
    }

    public function isClient(): bool
    {
        return in_array($this->type, ['client', 'both'], true);
    }

    public function isSupplier(): bool
    {
        return in_array($this->type, ['supplier', 'both'], true);
    }
}
