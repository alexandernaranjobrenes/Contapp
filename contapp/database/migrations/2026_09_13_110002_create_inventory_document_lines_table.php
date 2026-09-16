<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity', 18, 6)
                ->comment('siempre positiva; en un conteo es la cantidad CONTADA, no la diferencia');
            $table->decimal('unit_cost_local', 18, 6)
                ->comment('en entradas lo digita el usuario; en salidas y conteos lo resuelve el promedio');
            $table->decimal('unit_cost_foreign', 18, 6);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['inventory_document_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_document_lines');
    }
};
