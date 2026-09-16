<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Null en un almacén sin ubicaciones, obligatorio en uno que las usa. No se
 * puede hacer NOT NULL: los movimientos ya contabilizados antes de esta fase
 * no tienen ubicación, y el kardex es inviolable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table) {
            $table->foreignId('warehouse_bin_id')->nullable()->after('warehouse_id')->constrained()->nullOnDelete();
        });

        Schema::table('stock_journals', function (Blueprint $table) {
            $table->foreignId('warehouse_bin_id')->nullable()->after('warehouse_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_bin_id');
        });

        Schema::table('inventory_document_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_bin_id');
        });
    }
};
