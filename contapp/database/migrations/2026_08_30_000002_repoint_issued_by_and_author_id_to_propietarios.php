<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * licenses.issued_by y commercial_interactions.author_id apuntaban a
     * users (quien emitía una licencia o registraba una interacción
     * comercial siempre fue un platform admin, nunca un usuario de compañía
     * cualquiera) — ahora que Propietario es su propia tabla, se repuntan
     * ahí. Debe correr después del backfill de propietarios (migración
     * anterior) para poder resolver cada users.id ya migrado por email.
     */
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->foreignId('issued_by_new')->nullable()->after('issued_by')
                ->constrained('propietarios')->nullOnDelete();
        });

        Schema::table('commercial_interactions', function (Blueprint $table) {
            $table->foreignId('author_id_new')->nullable()->after('author_id')
                ->constrained('propietarios')->nullOnDelete();
        });

        $this->backfillByEmail('licenses', 'issued_by', 'issued_by_new');
        $this->backfillByEmail('commercial_interactions', 'author_id', 'author_id_new');

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_by');
            $table->renameColumn('issued_by_new', 'issued_by');
        });

        Schema::table('commercial_interactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_id');
            $table->renameColumn('author_id_new', 'author_id');
        });
    }

    private function backfillByEmail(string $table, string $oldColumn, string $newColumn): void
    {
        DB::table($table)->whereNotNull($oldColumn)->orderBy('id')->cursor()->each(function ($row) use ($table, $oldColumn, $newColumn) {
            $user = DB::table('users')->find($row->{$oldColumn});
            $propietarioId = $user ? DB::table('propietarios')->where('email', $user->email)->value('id') : null;

            if ($propietarioId === null) {
                Log::warning("Repunte de {$table}.{$oldColumn}: sin propietario correspondiente", ['row_id' => $row->id]);

                return;
            }

            DB::table($table)->where('id', $row->id)->update([$newColumn => $propietarioId]);
        });
    }

    /**
     * Mejor esfuerzo, no garantizado: repuntar de vuelta a users uniendo por
     * email en sentido contrario. Igual criterio que el backfill de
     * superuser_id — una reversión de datos no es una garantía dura.
     */
    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->foreignId('issued_by_old')->nullable()->after('issued_by')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('commercial_interactions', function (Blueprint $table) {
            $table->foreignId('author_id_old')->nullable()->after('author_id')
                ->constrained('users')->nullOnDelete();
        });

        $this->backfillByEmailReverse('licenses', 'issued_by', 'issued_by_old');
        $this->backfillByEmailReverse('commercial_interactions', 'author_id', 'author_id_old');

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_by');
            $table->renameColumn('issued_by_old', 'issued_by');
        });

        Schema::table('commercial_interactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_id');
            $table->renameColumn('author_id_old', 'author_id');
        });
    }

    private function backfillByEmailReverse(string $table, string $currentColumn, string $newColumn): void
    {
        DB::table($table)->whereNotNull($currentColumn)->orderBy('id')->cursor()->each(function ($row) use ($table, $currentColumn, $newColumn) {
            $propietario = DB::table('propietarios')->find($row->{$currentColumn});
            $userId = $propietario ? DB::table('users')->where('email', $propietario->email)->value('id') : null;

            if ($userId !== null) {
                DB::table($table)->where('id', $row->id)->update([$newColumn => $userId]);
            }
        });
    }
};
