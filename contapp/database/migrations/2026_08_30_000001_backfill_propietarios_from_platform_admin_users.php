<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Migración de datos, no de esquema: por cada users.is_platform_admin=true
     * (hoy solo el flag; con esta entrega deja de existir, ver la migración
     * que dropea la columna, que corre después de esta) crea su fila
     * equivalente en propietarios, copiando el hash de contraseña tal cual
     * (bcrypt es portable entre tablas) para que la cuenta siga entrando con
     * la misma clave, ahora en /backoffice/login. DB::table(), no el modelo
     * Eloquent User: para cuando esta migración corra de verdad, el modelo ya
     * puede no tener el cast/columna is_platform_admin.
     */
    public function up(): void
    {
        $platformAdmins = DB::table('users')->where('is_platform_admin', true)->get();

        if ($platformAdmins->isEmpty()) {
            Log::info('Backfill de propietarios: no había ningún users.is_platform_admin=true que migrar.');

            return;
        }

        foreach ($platformAdmins as $user) {
            DB::table('propietarios')->updateOrInsert(
                ['email' => $user->email],
                [
                    'name' => $user->name,
                    'password' => $user->password,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * No reversible de forma significativa: es un backfill de datos, no un
     * cambio de esquema (ese se deshace en su propia migración).
     */
    public function down(): void
    {
        //
    }
};
