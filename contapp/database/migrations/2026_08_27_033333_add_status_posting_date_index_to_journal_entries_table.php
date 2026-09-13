<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // LedgerService, FxRevaluationService y PeriodCloseService filtran
            // y ordenan siempre por esta combinación exacta (company_id +
            // status='posted' + rango de posting_date) — sin este índice,
            // esas consultas escanean la tabla completa a medida que crece.
            $table->index(['company_id', 'status', 'posting_date'], 'journal_entries_company_status_posting_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_company_status_posting_date_index');
        });
    }
};
