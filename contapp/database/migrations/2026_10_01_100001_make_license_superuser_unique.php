<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una cuenta es dueña de una sola licencia. Hasta ahora eso se cumplía de
     * rebote —activar exigía un correo nuevo, así que cada licencia nacía con
     * un usuario nuevo— pero no estaba escrito en ningún lado. Ahora que una
     * cuenta existente puede activar una licencia, la regla pasa a ser
     * explícita: la valida LicenseActivationService y la garantiza este
     * índice. Las licencias sin activar (superuser_id nulo) no chocan entre
     * sí: un índice único admite varios nulos.
     */
    public function up(): void
    {
        $duplicated = DB::table('licenses')
            ->whereNotNull('superuser_id')
            ->groupBy('superuser_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('superuser_id');

        if ($duplicated->isNotEmpty()) {
            throw new RuntimeException(
                'No se puede exigir una licencia por cuenta: estos usuarios figuran como dueños de más de una ('
                .$duplicated->implode(', ').'). Hay que resolverlo a mano antes de migrar.'
            );
        }

        Schema::table('licenses', function (Blueprint $table) {
            $table->unique('superuser_id', 'licenses_superuser_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropUnique('licenses_superuser_id_unique');
        });
    }
};
