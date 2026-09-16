<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una regla de la matriz de determinación de cuentas: "para esta categoría
 * contable, en este alcance, usar esta cuenta". La resolución por precedencia
 * vive en GlDeterminationResolver, no acá.
 */
class GlDetermination extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * Del más específico al más general — el orden de este arreglo ES la
     * precedencia que aplica GlDeterminationResolver.
     */
    public const SCOPE_LEVELS = [
        'item' => 'Artículo',
        'item_group' => 'Grupo de artículos',
        'warehouse' => 'Almacén',
        'company' => 'Compañía (predeterminada)',
    ];

    /**
     * Solo las categorías que hoy tienen un consumidor real. El diseño de
     * Fase 0 define otras cuatro (cogs, landed_cost_clearing,
     * write_down_allowance, write_down_expense) que se agregan cuando llegue
     * la fase que las use: una categoría configurable que ningún asiento lee
     * sería exactamente el esquema muerto que este módulo se comprometió a no
     * repetir.
     */
    public const CATEGORIES = [
        'inventory' => 'Inventario de mercancías',
        'gr_ir_clearing' => 'Transitoria de compras (GR/IR)',
        'stock_increase' => 'Ajuste de inventario — aumento',
        'stock_decrease' => 'Ajuste de inventario — disminución',
        'price_difference' => 'Diferencia de precio (mercancía ya vendida)',
        'wip' => 'Producto en proceso (WIP)',
        'production_variance' => 'Desviación de fabricación',
        'cogs' => 'Costo de mercancías vendidas',
    ];

    protected $fillable = [
        'company_id', 'scope_level', 'scope_id', 'category', 'account_id', 'cost_allocation_rule_id',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function costAllocationRule(): BelongsTo
    {
        return $this->belongsTo(CostAllocationRule::class);
    }
}
