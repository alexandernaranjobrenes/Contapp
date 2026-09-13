<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fx_revaluation_runs', function (Blueprint $table) {
            // Antes había una sola cuenta compartida para ganancia y pérdida
            // (parámetro $gainLossAccount) — se separan para que la ganancia
            // y la pérdida cambiaria queden en líneas de resultados distintas,
            // como es práctica contable estándar y como lo pide la pantalla
            // de criterios de selección (docs/decisiones.md 2026-08-24).
            $table->foreignId('gain_account_id')->nullable()->after('exchange_rate_used')->constrained('chart_of_accounts');
            $table->foreignId('loss_account_id')->nullable()->after('gain_account_id')->constrained('chart_of_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('fx_revaluation_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gain_account_id');
            $table->dropConstrainedForeignId('loss_account_id');
        });
    }
};
