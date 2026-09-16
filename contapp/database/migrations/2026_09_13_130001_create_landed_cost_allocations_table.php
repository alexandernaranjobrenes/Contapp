<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se repartió el costo entre los artículos de la recepción. Guarda las
 * cantidades con las que se calculó el reparto (recibida y en existencia al
 * momento) porque ambas cambian después: sin esa foto, un mes más tarde nadie
 * puede reconstruir por qué se capitalizó justo esa proporción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landed_cost_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('received_quantity', 18, 6)->comment('lo que trajo la línea de la recepción');
            $table->decimal('on_hand_quantity', 18, 6)->comment('existencia del artículo al momento de repartir');
            $table->decimal('allocated_amount', 18, 2)->comment('porción del total que le tocó a esta línea');
            $table->decimal('capitalized_amount', 18, 2);
            $table->decimal('expensed_amount', 18, 2);
            $table->timestamps();

            $table->index('landed_cost_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
    }
};
