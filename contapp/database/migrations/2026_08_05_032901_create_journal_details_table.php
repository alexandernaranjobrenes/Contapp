<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('business_partner_id')->nullable()
                ->comment('FK a business_partners se agrega en la migración de Fase 1 (CxC/CxP)');
            $table->foreignId('currency_id')->constrained()->comment('moneda "natural" en que nació la línea');
            $table->decimal('exchange_rate_lc_fc', 18, 6)->nullable();
            $table->decimal('exchange_rate_fc_sc', 18, 6)->nullable();
            $table->decimal('debit_local', 18, 2)->default(0);
            $table->decimal('credit_local', 18, 2)->default(0);
            $table->decimal('debit_foreign', 18, 2)->default(0);
            $table->decimal('credit_foreign', 18, 2)->default(0);
            $table->decimal('debit_system', 18, 2)->default(0);
            $table->decimal('credit_system', 18, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('reference_document')->nullable();
            $table->boolean('bank_reconciled')->default(false);
            $table->timestamps();

            $table->index(['journal_entry_id', 'line_number']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_details');
    }
};
