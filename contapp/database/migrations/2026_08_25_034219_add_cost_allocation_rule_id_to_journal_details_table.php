<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_details', function (Blueprint $table) {
            // Trazabilidad de qué norma generó un grupo de filas explotadas
            // (una fila por PostJournalService::post()); cost_center_id (ya
            // existente) sigue guardando el centro específico de CADA fila,
            // así el mayor auxiliar por centro de costo (LedgerService) no
            // necesita ningún cambio. nullOnDelete: un movimiento ya
            // contabilizado nunca se bloquea por borrar la norma, solo
            // pierde la etiqueta de trazabilidad (mismo criterio que
            // cost_center_id ya tenía).
            $table->foreignId('cost_allocation_rule_id')->nullable()
                ->after('cost_center_id')
                ->constrained('cost_allocation_rules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_allocation_rule_id');
        });
    }
};
