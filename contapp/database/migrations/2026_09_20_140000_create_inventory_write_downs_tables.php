<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deterioro de inventario (NIC 2 §28-33): valuar al MENOR entre costo y valor
 * neto realizable.
 *
 * No es un movimiento de inventario y por eso no vive en inventory_documents:
 * no cambia cantidades, no toca el kardex y no altera el costo promedio. Es
 * una ESTIMACIÓN contra-activo que presenta el inventario neto sin tocar su
 * costo — si rebajara el costo, el promedio móvil quedaría corrupto y el
 * kardex dejaría de cuadrar con la contabilidad, que es justo la invariante
 * que sostiene el módulo.
 *
 * La Fase 0 lo previó así: "es un proceso periódico manual, no un asiento
 * automático por transacción".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_write_downs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained();
            // El asiento es obligatorio: no hay estimación registrada sin su
            // contrapartida contable, misma invariante que inventory_documents.
            $table->foreignId('journal_entry_id')->constrained();
            $table->date('as_of');
            $table->string('description')->nullable();
            $table->string('status')->default('posted');
            $table->foreignId('reversal_of_id')->nullable()->constrained('inventory_write_downs');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'as_of']);
        });

        Schema::create('inventory_write_down_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_write_down_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('item_id')->constrained();
            // Foto del costo al corte, para poder auditar la estimación sin
            // tener que reconstruir el kardex de esa fecha otra vez.
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost_local', 18, 6);
            $table->decimal('cost_value_local', 18, 2);
            // Valor neto realizable estimado por quien hace el avalúo: precio
            // de venta esperado menos costos de terminación y de venta.
            $table->decimal('nrv_unit_local', 18, 6);
            $table->decimal('nrv_value_local', 18, 2);
            // Estimación que DEBERÍA existir tras este avalúo: max(0, costo − VNR).
            $table->decimal('target_allowance_local', 18, 2);
            // Lo que ya estaba estimado de avalúos anteriores.
            $table->decimal('previous_allowance_local', 18, 2);
            // El delta contabilizado: positivo deteriora, negativo reversa
            // (NIC 2 §33). La suma de esta columna por artículo ES la
            // estimación acumulada — no se almacena un saldo aparte.
            $table->decimal('movement_local', 18, 2);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_write_down_lines');
        Schema::dropIfExists('inventory_write_downs');
    }
};
