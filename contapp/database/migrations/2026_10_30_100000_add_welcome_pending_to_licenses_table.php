<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bienvenida de una licencia recién activada (CLAUDE.md secc. 12): el
 * recorrido por Pivox que ve su dueño la primera vez que entra. Se prende al
 * activarla (LicenseActivationService) y se apaga cuando el dueño lo termina
 * o lo salta (WelcomeTourController).
 *
 * Arranca en falso: las licencias que ya estaban activas no lo ven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->boolean('welcome_pending')->default(false)->after('superuser_id');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn('welcome_pending');
        });
    }
};
