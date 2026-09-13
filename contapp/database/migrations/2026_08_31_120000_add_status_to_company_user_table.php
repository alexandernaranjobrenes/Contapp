<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            // Estado de ESTA membresía usuario-compañía — nunca global. Un
            // Superusuario solo puede suspender/desactivar el acceso de un
            // Administrador/Usuario a las compañías de SU PROPIA licencia;
            // si esa misma persona participa en otra licencia (ver
            // PermissionGrantService::inviteUser()), esas membresías quedan
            // intactas. Deliberadamente NO se usa users.status (global) para
            // esto, que es justo el problema que rompería la independencia
            // de identidad entre licencias.
            $table->string('status')->default('active')->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
