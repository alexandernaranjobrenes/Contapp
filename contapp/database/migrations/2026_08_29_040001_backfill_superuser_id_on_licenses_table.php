<?php

use App\Domains\Licensing\Models\License;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Migración de datos, no de esquema: fija superuser_id en licencias ya
     * activadas antes de que la columna existiera, leyendo el único usuario
     * is_super_admin=true conectado a las compañías de cada licencia. Una
     * licencia con cero o más de un candidato se deja sin tocar (registrada
     * en el log) en vez de adivinar — no debería pasar en este proyecto en
     * etapa de desarrollo, pero la migración no debe asumirlo.
     */
    public function up(): void
    {
        License::whereNull('superuser_id')->cursor()->each(function (License $license) {
            $companyIds = $license->companies()->pluck('id');

            if ($companyIds->isEmpty()) {
                return;
            }

            $candidateIds = DB::table('users')
                ->join('company_user', 'company_user.user_id', '=', 'users.id')
                ->whereIn('company_user.company_id', $companyIds)
                ->where('users.is_super_admin', true)
                ->distinct()
                ->pluck('users.id');

            if ($candidateIds->count() === 1) {
                $license->forceFill(['superuser_id' => $candidateIds->first()])->save();

                return;
            }

            Log::warning('Backfill de licenses.superuser_id: candidato ambiguo o ausente', [
                'license_id' => $license->id,
                'candidate_ids' => $candidateIds->all(),
            ]);
        });
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
