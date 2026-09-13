<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta ahora journal_entries solo tenía document_date, que hacía doble
 * función: dato informativo (la fecha del documento fuente) Y fecha rectora
 * (la que resolvía período fiscal, tipo de cambio y vigencias). Se separan
 * los dos roles: posting_date (fecha de contabilización) pasa a ser la
 * rectora; document_date queda como dato de referencia puro. due_date
 * (fecha de vencimiento) es nueva, opcional, a nivel de encabezado —
 * precarga el vencimiento de cada línea (journal_details.due_date, que ya
 * existía) al agregarla, editable línea por línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->date('posting_date')->nullable()->after('document_date');
            $table->date('due_date')->nullable()->after('posting_date');
        });

        // Backfill: para todo asiento ya existente, la fecha de
        // contabilización arranca igual a la fecha de documento que ya
        // tenía — no hay forma de reconstruir cuál "debió" haber sido,
        // así que se asume que coincidían hasta ahora.
        DB::table('journal_entries')->update(['posting_date' => DB::raw('document_date')]);

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->date('posting_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn(['posting_date', 'due_date']);
        });
    }
};
