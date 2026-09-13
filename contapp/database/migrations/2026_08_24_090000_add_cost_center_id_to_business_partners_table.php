<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Para poder analizar/reportar ventas o saldos por centro de costo además de
 * por categoría — igual que category_id/family_id (bp_categories/bp_families,
 * Fase 0), es una referencia informativa: no participa de ninguna validación
 * de PostJournalService, no exige que el centro sea hoja ni vigente. Es un
 * "a qué centro pertenece este socio" para reportes, no una cuenta que vaya
 * a recibir movimientos directos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('family_id')
                ->constrained('cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
        });
    }
};
