<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Distinto de is_super_admin: is_super_admin es el rol tope
            // DENTRO de las compañías a las que el usuario pertenece;
            // is_platform_admin es quien opera CONTAPP como negocio (emite
            // y renueva licencias, ve todas las compañías). Casi siempre
            // solo lo tiene el dueño de la plataforma, nunca un cliente.
            $table->boolean('is_platform_admin')->default(false)->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
