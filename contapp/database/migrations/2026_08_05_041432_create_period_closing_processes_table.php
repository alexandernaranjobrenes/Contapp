<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_closing_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods');
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years');
            $table->string('type')->comment('month_close|month_reopen|year_close');
            $table->foreignId('document_type_id')->nullable()->constrained('document_types');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_closing_processes');
    }
};
