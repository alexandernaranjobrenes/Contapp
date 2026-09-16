<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Existencia por ubicación. Desglosa —nunca reemplaza— a item_warehouses:
 * la suma de las ubicaciones de un almacén es siempre igual a su on_hand, y
 * el resto del sistema (costeo, reportes, validaciones) sigue leyendo el
 * nivel de almacén sin enterarse de que existen ubicaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_bin_id')->constrained()->cascadeOnDelete();
            $table->decimal('on_hand', 18, 6)->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_bin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_bins');
    }
};
