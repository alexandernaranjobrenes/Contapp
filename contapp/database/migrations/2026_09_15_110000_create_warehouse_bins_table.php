<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicaciones dentro de un almacén (pasillo, estante, posición).
 *
 * Es una capa puramente LOGÍSTICA: el costo promedio sigue siendo global por
 * artículo, así que mover una unidad entre ubicaciones no cambia su valor ni
 * genera asiento. Por eso se agrega sin tocar el motor de costeo
 * (docs/decisiones.md 2026-09-15 Fase 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name')->nullable();
            $table->string('status')->default('active')->comment('active|inactive');
            $table->timestamps();

            $table->unique(['warehouse_id', 'code']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            // Un almacén con ubicaciones EXIGE indicarlas en cada movimiento;
            // uno sin ellas sigue funcionando exactamente como antes. Es la
            // bandera que hace que esta fase sea opcional por almacén.
            $table->boolean('uses_bins')->default(false)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('uses_bins');
        });

        Schema::dropIfExists('warehouse_bins');
    }
};
