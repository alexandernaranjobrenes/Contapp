<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orden de fabricación. El costo real se acumula en la cuenta de Producto en
 * Proceso (WIP) a medida que se emiten materias primas, y se descarga cuando
 * ingresa el producto terminado.
 *
 * A propósito SIN lista de materiales (BOM): el plan de componentes es una
 * conveniencia de catálogo, no un hecho contable — se emite lo que de verdad
 * se consumió. Una BOM se agrega si aparece la necesidad de planificar, no
 * para poder costear (docs/decisiones.md 2026-09-15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->comment('el producto a fabricar');
            $table->foreignId('warehouse_id')->constrained()->comment('dónde ingresa el producto terminado');
            $table->decimal('planned_quantity', 18, 6);
            $table->decimal('produced_quantity', 18, 6)->default(0);
            $table->date('order_date');
            $table->string('description')->nullable();
            $table->string('status')->default('open')->comment('open|closed');
            $table->foreignId('variance_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete()
                ->comment('asiento que descargó el WIP sobrante al cerrar; null si cerró en cero');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
