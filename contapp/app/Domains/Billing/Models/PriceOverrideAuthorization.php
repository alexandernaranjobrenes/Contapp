<?php

namespace App\Domains\Billing\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\PriceList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El rastro de un cambio de precio liberado por un administrador. Ver el
 * encabezado de la migración sobre por qué es tabla propia y por qué el
 * precio de lista se guarda congelado.
 */
class PriceOverrideAuthorization extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sales_document_id', 'sales_document_line_id',
        'sales_order_id', 'sales_order_line_id', 'item_id',
        'price_list_id', 'price_list_code', 'list_unit_price', 'invoiced_unit_price',
        'difference', 'requested_by', 'authorized_by', 'reason',
    ];

    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    /**
     * Una autorización cuelga de un pedido O de una factura, nunca de las
     * dos: el precio se firma una vez, y si se firmó en el pedido la
     * factura que lo cumple no vuelve a pedirlo.
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
