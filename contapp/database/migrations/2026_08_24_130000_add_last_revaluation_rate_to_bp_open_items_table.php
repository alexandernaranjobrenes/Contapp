<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bp_open_items', function (Blueprint $table) {
            $table->decimal('last_revaluation_rate', 18, 6)->nullable()->after('currency_id')
                ->comment('tipo de cambio de la última revaluación de cierre (FxRevaluationService) que incluyó esta partida — si está presente, ApplyPaymentService lo usa como base del diferencial REALIZADO en vez del tipo de cambio original de la línea, para no contar dos veces la porción ya reconocida como diferencial no realizado');
        });
    }

    public function down(): void
    {
        Schema::table('bp_open_items', function (Blueprint $table) {
            $table->dropColumn('last_revaluation_rate');
        });
    }
};
