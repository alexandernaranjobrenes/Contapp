<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at');
            $table->timestamps();

            $table->index('account_id');
        });

        Schema::create('account_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_reconciliation_id')->constrained('account_reconciliations')->cascadeOnDelete();
            $table->foreignId('journal_detail_id')->constrained('journal_details');
            $table->timestamps();

            // Una línea solo puede formar parte de UNA reconciliación activa a
            // la vez — deshacerla (delete físico de account_reconciliations,
            // ver AccountReconciliationService::unreconcile()) la libera.
            $table->unique('journal_detail_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reconciliation_lines');
        Schema::dropIfExists('account_reconciliations');
    }
};
