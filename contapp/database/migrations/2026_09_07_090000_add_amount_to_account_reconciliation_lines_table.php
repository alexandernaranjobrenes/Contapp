<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Habilita la reconciliación parcial: un journal_detail ya no queda
 * "consumido" de una vez por una sola reconciliación (unique(journal_detail_id)
 * lo impedía) — ahora cada línea de reconciliación registra el monto
 * puntual que aplicó, y el saldo disponible del movimiento es
 * abs(debit_local - credit_local) menos la suma de lo ya reconciliado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_reconciliation_lines', function (Blueprint $table) {
            // El unique de journal_detail_id es también el índice que sostiene
            // su FK — hay que darle un índice de reemplazo en el mismo ALTER
            // TABLE antes de soltarlo, si no MySQL rechaza el drop (error 1553).
            $table->index('journal_detail_id', 'acct_recon_lines_detail_idx');
            $table->dropUnique(['journal_detail_id']);
            $table->decimal('amount', 18, 2)->nullable()->after('journal_detail_id');
        });

        // Backfill: las reconciliaciones ya existentes eran siempre de monto
        // completo (no había parcialidad antes de esta migración). Portable
        // (MySQL en producción, SQLite en tests) en vez de un UPDATE...JOIN.
        DB::table('account_reconciliation_lines')->orderBy('id')->chunkById(200, function ($lines) {
            foreach ($lines as $line) {
                $detail = DB::table('journal_details')->find($line->journal_detail_id);

                if ($detail) {
                    DB::table('account_reconciliation_lines')->where('id', $line->id)->update([
                        'amount' => abs($detail->debit_local - $detail->credit_local),
                    ]);
                }
            }
        });

        Schema::table('account_reconciliation_lines', function (Blueprint $table) {
            $table->decimal('amount', 18, 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('account_reconciliation_lines', function (Blueprint $table) {
            $table->dropColumn('amount');
            $table->unique('journal_detail_id');
            $table->dropIndex('acct_recon_lines_detail_idx');
        });
    }
};
