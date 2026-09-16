<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Única fuente de verdad de la existencia física. No lleva company_id: su
 * aislamiento multiempresa lo hereda de items/warehouses, que sí lo tienen —
 * agregarlo acá permitiría que la fila contradiga a su propio artículo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('on_hand', 18, 6)->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_warehouses');
    }
};
