<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->foreignId('item_group_id')->nullable()->constrained('item_groups')->nullOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure');
            $table->string('barcode')->nullable();
            $table->boolean('is_inventory_item')->default(true)
                ->comment('false = servicio: se compra/vende pero no lleva kardex ni costo');
            $table->boolean('is_sales_item')->default(true);
            $table->boolean('is_purchase_item')->default(true);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete()
                ->comment('indicador de impuesto por defecto de la línea');

            // Promedio ponderado móvil, GLOBAL por artículo (no por almacén).
            // Es la única excepción declarada a "los saldos nunca se almacenan"
            // de CLAUDE.md: un promedio móvil es dependiente de la trayectoria,
            // no existe consulta puntual que lo derive (docs/decisiones.md
            // 2026-09-13). La CANTIDAD sí se deriva: vive solo en
            // item_warehouses.on_hand y el total del artículo es un SUM.
            $table->decimal('avg_cost_local', 18, 6)->default(0);
            $table->decimal('avg_cost_foreign', 18, 6)->default(0)
                ->comment('avg_cost_local/avg_cost_foreign es el TC congelado del stock');

            $table->string('status')->default('active')->comment('active|inactive');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'item_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
