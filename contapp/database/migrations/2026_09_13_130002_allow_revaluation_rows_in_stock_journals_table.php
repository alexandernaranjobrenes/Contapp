<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una revaluación (landed cost, diferencia de precio) cambia el VALOR del
 * inventario sin mover una sola unidad. Tiene que quedar en el kardex igual
 * que un movimiento: sin eso, la columna avg_cost_local_after de la fila
 * anterior mentiría y nadie podría explicar por qué cambió el promedio.
 *
 * Por eso una fila del kardex nace ahora de UNA de dos cosas —un movimiento
 * de stock o un reparto de costo— y exactamente una de las dos FK viene con
 * valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_journals', function (Blueprint $table) {
            $table->foreignId('inventory_document_line_id')->nullable()->change();

            $table->foreignId('landed_cost_allocation_id')->nullable()->after('inventory_document_line_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landed_cost_allocation_id');
            $table->foreignId('inventory_document_line_id')->nullable(false)->change();
        });
    }
};
