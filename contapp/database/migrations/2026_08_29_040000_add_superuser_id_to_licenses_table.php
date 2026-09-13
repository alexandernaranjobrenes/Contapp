<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            // Nullable: licencias emitidas pero todavía sin canjear no tienen
            // superusuario todavía; las ya activadas antes de este cambio se
            // backfillean en una migración de datos aparte. nullOnDelete: si
            // el usuario se borra, la licencia no debe desaparecer ni
            // bloquearse, solo perder la referencia.
            $table->foreignId('superuser_id')->nullable()->after('issued_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superuser_id');
        });
    }
};
