<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Null en un artículo que no maneja lotes, obligatorio en uno que sí. No se
 * puede hacer NOT NULL, por la misma razón que warehouse_bin_id no pudo en
 * la Fase 7: los movimientos ya contabilizados antes de esta fase no tienen
 * lote, y el kardex es inviolable.
 *
 * nullOnDelete y no cascade: borrar un lote del maestro jamás debe borrar un
 * movimiento de kardex. En la práctica ese borrado ni siquiera se permite
 * (un lote con movimientos no se elimina, ver ItemLotController), pero la
 * restricción se declara por si alguien llega por otro camino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table) {
            $table->foreignId('item_lot_id')->nullable()->after('warehouse_bin_id')->constrained()->nullOnDelete();
        });

        Schema::table('stock_journals', function (Blueprint $table) {
            $table->foreignId('item_lot_id')->nullable()->after('warehouse_bin_id')->constrained()->nullOnDelete();
            // El reporte de trazabilidad entra siempre por "dónde anduvo este
            // lote": sin índice es un barrido del kardex completo, que es la
            // tabla que más crece del módulo.
            $table->index(['item_lot_id', 'posting_date']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_journals', function (Blueprint $table) {
            $table->dropIndex(['item_lot_id', 'posting_date']);
            $table->dropConstrainedForeignId('item_lot_id');
        });

        Schema::table('inventory_document_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_lot_id');
        });
    }
};
