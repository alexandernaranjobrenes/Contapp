<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corre después de backfillear propietarios y repuntar issued_by/
     * author_id (migraciones anteriores): para entonces ya nada depende de
     * este flag — "ser Propietario" pasa a significar únicamente "estar
     * autenticado en el guard propietario" (ver EnsurePlatformAdmin, retirado
     * en esta misma entrega en favor de auth:propietario).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('is_super_admin');
        });
    }
};
