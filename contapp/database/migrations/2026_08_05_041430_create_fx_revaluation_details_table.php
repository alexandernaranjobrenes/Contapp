<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluation_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fx_revaluation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->foreignId('business_partner_id')->nullable()->constrained('business_partners')->nullOnDelete();
            $table->decimal('foreign_balance', 18, 2);
            $table->decimal('historical_local_amount', 18, 2);
            $table->decimal('revalued_local_amount', 18, 2);
            $table->decimal('difference', 18, 2);
            $table->foreignId('journal_detail_id')->nullable()->constrained('journal_details');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluation_details');
    }
};
