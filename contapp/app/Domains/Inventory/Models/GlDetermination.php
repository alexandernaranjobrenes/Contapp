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
     * Las once categorías del diseño de Fase 0, ya todas con consumidor real.
     * Las dos últimas —deterioro NIC 2— se activaron con
     * PostInventoryWriteDownService, que es la fase que por fin las usa; la
     * regla de no declarar categorías que ningún asiento lee se mantuvo hasta
     * ese momento.
     */
    public const CATEGORIES = [
        'inventory' => 'Inventario de mercancías',
        'gr_ir_clearing' => 'Transitoria de compras (GR/IR)',
        'landed_cost_clearing' => 'Costos de importación por asignar',
        'stock_increase' => 'Ajuste de inventario — aumento',
        'stock_decrease' => 'Ajuste de inventario — disminución',
        'price_difference' => 'Diferencia de precio (mercancía ya vendida)',
        'wip' => 'Producto en proceso (WIP)',
        'production_variance' => 'Desviación de fabricación',
        'cogs' => 'Costo de mercancías vendidas',
        // NIC 2 §28. La estimación es CONTRA-ACTIVO: no rebaja el costo del
        // inventario (eso corrompería el promedio móvil y separaría el kardex
        // de la contabilidad), lo presenta neto.
        'write_down_allowance' => 'Estimación por deterioro de inventario',
        'write_down_expense' => 'Gasto por deterioro de inventario',
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
