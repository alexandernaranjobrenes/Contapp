<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kardex (OINM). Append-only: sin updated_at porque una fila nunca se edita
 * ni se borra — una corrección es un documento de reversión que agrega filas
 * nuevas, mismo criterio que journal_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('inventory_document_line_id')->constrained();
            $table->foreignId('journal_entry_id')->constrained();
            $table->date('posting_date');
            $table->string('direction')->comment('in|out — el signo lo da esta columna, quantity siempre es positiva');
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost_local', 18, 6);
            $table->decimal('unit_cost_foreign', 18, 6);
            $table->decimal('total_cost_local', 18, 2)->comment('ya redondeado: es el monto exacto que fue al asiento');
            $table->decimal('total_cost_foreign', 18, 2);
            $table->decimal('balance_quantity', 18, 6)
                ->comment('existencia del almacén después de este movimiento — foto de auditoría, no fuente de verdad');
            $table->decimal('avg_cost_local_after', 18, 6)->comment('promedio global del artículo después de este movimiento');
            $table->decimal('avg_cost_foreign_after', 18, 6);
            $table->foreignId('reversal_of_id')->nullable()->constrained('stock_journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'item_id', 'warehouse_id', 'posting_date'], 'stock_journals_item_warehouse_date_index');
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_journals');
    }
};
