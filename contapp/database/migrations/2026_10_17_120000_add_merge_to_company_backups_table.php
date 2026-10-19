<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un respaldo también puede completar una compañía que ya existe
     * (CLAUDE.md secc. 37), y se revisa en la cola, no al subirlo.
     *
     * - summary: vacío mientras se revisa en la cola.
     * - mode: «new» (una compañía nueva) o «merge» (completar una que existe).
     * - target_company_id: la compañía que se completa.
     * - comparison: lo que le falta, lo que ya tiene y lo que impide
     *   completarla (la comparación antes de confirmar), y al terminar, lo
     *   que se agregó.
     */
    public function up(): void
    {
        Schema::table('company_backups', function (Blueprint $table) {
            $table->json('summary')->nullable()->change();
            $table->string('status', 20)->default('validated')
                ->comment('inspecting|validated|comparing|compared|queued|importing|done|failed|expired')
                ->change();
            $table->string('mode', 10)->nullable()->after('status')->comment('new|merge');
            $table->foreignId('target_company_id')->nullable()->after('mode')->constrained('companies')->nullOnDelete();
            $table->json('comparison')->nullable()->after('company_data');
        });
    }

    public function down(): void
    {
        Schema::table('company_backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_company_id');
            $table->dropColumn(['mode', 'comparison']);
        });
    }
};
