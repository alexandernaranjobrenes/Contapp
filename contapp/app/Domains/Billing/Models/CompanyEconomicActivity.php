<?php

namespace App\Domains\Billing\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actividad económica inscrita en el RUT del emisor. De cuál se factura
 * depende a qué cuenta de ingresos va la venta, que es la razón de que la
 * cuenta viva acá y no en la matriz de inventario.
 */
class CompanyEconomicActivity extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'revenue_account_id', 'is_default', 'status',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id');
    }
}
