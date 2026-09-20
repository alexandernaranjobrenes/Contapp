<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes: de dónde viene cada unidad y cuándo vence. Es una capa de
 * TRAZABILIDAD, no de valoración — el costo sigue siendo promedio ponderado
 * móvil global por artículo, igual que con las ubicaciones (Fase 7). Un lote
 * no guarda costo y el motor de costeo no lo mira nunca: por eso esta fase
 * no toca una sola línea del cálculo de promedios.
 *
 * NIC 2 §25 admite promedio ponderado para bienes ordinariamente
 * intercambiables entre sí, que es el caso que cubre este diseño. Si algún
 * día hubiera mercancía NO intercambiable (§23 exige identificación
 * específica), eso es otra decisión y otro motor de costeo, no una columna
 * más acá.
 *
 * A diferencia de las ubicaciones, que son propiedad del ALMACÉN
 * (warehouses.uses_bins), los lotes son propiedad del ARTÍCULO
 * (items.tracks_lots): un medicamento necesita lote en todos los almacenes,
 * y un cable no lo necesita en ninguno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('tracks_lots')->default(false)->after('is_purchase_item');
        });

        // Maestro de lotes. Cuelga de item_id y no lleva company_id propio:
        // el CompanyScope de Item es lo que lo aísla, mismo criterio que
        // warehouse_bins con su almacén.
        Schema::create('item_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            // Nullable: no todo lote vence. Un lote de fabricación sirve para
            // trazabilidad y recall aunque el producto no caduque.
            $table->date('expires_at')->nullable();
            // 'blocked' retiene el lote sin darlo de baja: no se puede
            // despachar, pero su existencia sigue contando en el inventario
            // (cuarentena, pendiente de análisis, retenido por calidad).
            $table->string('status')->default('active');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'code']);
            // El reporte de próximos a vencer y la sugerencia FEFO ordenan
            // por esta columna sobre lotes con saldo; sin índice, cada
            // consulta barre todo el maestro de lotes de la compañía.
            $table->index(['item_id', 'expires_at']);
        });

        // Existencia por lote. Desglosa —nunca reemplaza— a item_warehouses
        // y a item_bins: la suma de los lotes de un almacén es igual a su
        // on_hand, y la suma de los lotes de una ubicación es igual a la de
        // esa ubicación. El resto del sistema sigue leyendo los niveles de
        // siempre sin enterarse de que existen lotes.
        Schema::create('item_lot_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            // Null en un almacén sin ubicaciones; con valor en uno que las
            // usa. Es la cuarta dimensión (artículo, almacén, ubicación,
            // lote) y por eso entra en la clave única.
            $table->foreignId('warehouse_bin_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('on_hand', 18, 6)->default(0);
            $table->timestamps();

            // Nombre explícito: el que genera Laravel para cuatro columnas
            // pasa de los 64 caracteres de MySQL (ver docs/decisiones.md
            // 2026-08-05, "nombres de índice compuesto que exceden el límite").
            $table->unique(['item_lot_id', 'warehouse_id', 'warehouse_bin_id'], 'item_lot_stock_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_lot_stock');
        Schema::dropIfExists('item_lots');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('tracks_lots');
        });
    }
};
