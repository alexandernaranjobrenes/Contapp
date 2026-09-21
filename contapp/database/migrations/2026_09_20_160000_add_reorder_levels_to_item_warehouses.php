<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Punto de reorden: cuándo hay que volver a comprar y hasta dónde reponer.
 *
 * ── Por qué van en item_warehouses y no en una tabla aparte ──────────────
 *
 * Es configuración conviviendo con saldos, que normalmente conviene separar.
 * Acá no, por dos razones concretas:
 *
 *   1. La identidad es la misma —(artículo, almacén)—, así que una tabla
 *      aparte duplicaría filas y obligaría a un join en el único cálculo que
 *      las usa, que además necesita on_hand, reserved y ordered de esta
 *      misma fila.
 *   2. Las únicas vías que borran filas de item_warehouses lo hacen JUNTO con
 *      su artículo o su almacén (ver ItemController::destroy y
 *      WarehouseController::destroy). Perder la configuración de reorden de
 *      algo que se está eliminando no es un bug, es lo correcto. Si alguna
 *      vez apareciera un borrado de filas en cero sin borrar el padre, esta
 *      decisión habría que revisarla.
 *
 * ── Los dos niveles ──────────────────────────────────────────────────────
 *
 * minimum_stock dispara: cuando lo disponible cae a ese nivel o por debajo,
 * hay que comprar. maximum_stock dice hasta dónde reponer; sin él, la
 * sugerencia solo devuelve al mínimo.
 *
 * "Disponible" para decidir una compra NO es on_hand:
 *
 *     disponible = on_hand − reserved + ordered
 *
 * Lo apartado por un pedido ya tiene dueño y no cubre demanda futura; lo que
 * viene en camino sí la cubre, y omitirlo haría comprar de nuevo algo que ya
 * se pidió. Ese último término es la razón de que la orden de compra tuviera
 * que construirse antes que esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_warehouses', function (Blueprint $table) {
            $table->decimal('minimum_stock', 18, 6)->default(0)->after('ordered')
                ->comment('nivel que dispara la reposición; 0 = sin control de reorden');
            $table->decimal('maximum_stock', 18, 6)->nullable()->after('minimum_stock')
                ->comment('nivel hasta el que se repone; null = reponer solo hasta el mínimo');
        });
    }

    public function down(): void
    {
        Schema::table('item_warehouses', function (Blueprint $table) {
            $table->dropColumn(['minimum_stock', 'maximum_stock']);
        });
    }
};
