<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_allocation_rule_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_allocation_rule_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete a propósito: borrar un centro de costo que una
            // norma todavía reparte rompería la suma de 100% en silencio —
            // hay que quitarlo de la norma primero (o inactivar el centro).
            $table->foreignId('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
            $table->decimal('percentage', 5, 2);
            // Determina qué línea absorbe el remanente de redondeo al
            // repartir un monto (CostAllocationSplitter) — no se puede
            // depender del id autoincremental porque editar una norma borra
            // y recrea sus líneas.
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            // Nombre explícito: el auto-generado supera el límite de 64
            // caracteres de MySQL para identificadores.
            $table->unique(['cost_allocation_rule_id', 'cost_center_id'], 'cost_allocation_rule_lines_rule_center_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_allocation_rule_lines');
    }
};
