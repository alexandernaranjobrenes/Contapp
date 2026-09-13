<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Norma de reparto" reemplaza la jerarquía: un centro de costo ya no
        // recibe una asignación directa por línea (que sí necesitaba un árbol
        // de hoja/agrupador) — recibe su porción vía el reparto por
        // porcentaje de una norma. cost_centers vuelve a ser un catálogo
        // plano (docs/decisiones.md 2026-08-25).
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('level');
        });
    }

    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('company_id')->constrained('cost_centers')->nullOnDelete();
            $table->unsignedTinyInteger('level')->default(1)->after('parent_id')->comment('1 a 3 (MAX_LEVEL)');
        });
    }
};
