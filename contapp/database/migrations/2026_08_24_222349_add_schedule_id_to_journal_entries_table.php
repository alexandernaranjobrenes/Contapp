<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Marca los borradores que un JournalEntrySchedule generó
            // automáticamente — "Registros pendientes programados" filtra por
            // esta columna. nullOnDelete: si se borra la programación (no hay
            // delete físico de asientos ya contabilizados, pero los borradores
            // generados y todavía sin revisar quedan como huérfanos normales).
            $table->foreignId('schedule_id')->nullable()
                ->after('reversal_of_id')
                ->constrained('journal_entry_schedules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_id');
        });
    }
};
