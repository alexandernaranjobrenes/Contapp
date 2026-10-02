<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuándo la persona eligió ELLA su contraseña. Nulo significa que la
     * contraseña vigente la definió quien le creó la cuenta (un Superusuario
     * o un Administrador dando de alta a alguien en Administración →
     * Usuarios), que por lo tanto la conoce.
     *
     * Importa en un solo lugar: activar una licencia con una cuenta que ya
     * existe (LicenseActivationService::activateForExistingUser). Quien pasa
     * a ser dueño de una licencia propia no puede seguir con una contraseña
     * que conoce el Superusuario de OTRA licencia.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_chosen_at')->nullable()->after('password');
        });

        // Quien activó una licencia eligió su contraseña en ese formulario.
        DB::table('users')
            ->whereIn('id', DB::table('licenses')->whereNotNull('superuser_id')->select('superuser_id'))
            ->update(['password_chosen_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);

        // Y quien ya recuperó su contraseña por correo, también.
        DB::table('audit_logs')
            ->where('action', 'user.password_reset')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get(['user_id', 'created_at'])
            ->each(fn ($log) => DB::table('users')
                ->where('id', $log->user_id)
                ->update(['password_chosen_at' => $log->created_at]));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_chosen_at');
        });
    }
};
