<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('cutoff_date');
            $table->decimal('exchange_rate_used', 18, 6);
            $table->foreignId('document_type_id')->constrained('document_types');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->string('status')->default('completed');
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluation_runs');
    }
};
