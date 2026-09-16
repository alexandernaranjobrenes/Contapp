<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traslado entre almacenes.
 *
 * Es el único movimiento que puede NO tener asiento, y vale explicar por qué.
 * La invariante del módulo nunca fue "todo documento tiene asiento" sino "todo
 * cambio en el VALOR del inventario tiene su asiento": un traslado cambia
 * dónde está la mercancía, no cuánto vale. Si origen y destino resuelven a la
 * misma cuenta —el caso normal—, el asiento sería Debe X / Haber X por el
 * mismo monto: consumiría un consecutivo y dejaría en el libro diario una
 * partida numerada que no cambia nada. Es lo mismo que hace SAP B1.
 *
 * Cuando los almacenes SÍ tienen cuentas distintas, el traslado reclasifica
 * valor entre ellas y su asiento se genera normalmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->change();
        });

        Schema::table('stock_journals', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->change();
        });

        Schema::table('inventory_document_lines', function (Blueprint $table) {
            $table->foreignId('to_warehouse_id')->nullable()->after('warehouse_bin_id')
                ->constrained('warehouses')->nullOnDelete()
                ->comment('almacén de destino; solo en traslados');
            $table->foreignId('to_warehouse_bin_id')->nullable()->after('to_warehouse_id')
                ->constrained('warehouse_bins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('to_warehouse_bin_id');
            $table->dropConstrainedForeignId('to_warehouse_id');
        });

        Schema::table('stock_journals', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable(false)->change();
        });

        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable(false)->change();
        });
    }
};
