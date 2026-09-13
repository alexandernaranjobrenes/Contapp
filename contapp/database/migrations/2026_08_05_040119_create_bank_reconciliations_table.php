<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('cutoff_date');
            $table->decimal('bank_balance', 18, 2);
            $table->decimal('book_balance', 18, 2);
            $table->decimal('unrecorded_deposits', 18, 2)->default(0)->comment('depósitos en libros aún no acreditados por el banco');
            $table->decimal('unpaid_checks', 18, 2)->default(0)->comment('cheques girados en libros aún no pagados por el banco');
            $table->decimal('unrecorded_bank_credits', 18, 2)->default(0)->comment('créditos del banco aún no registrados en libros');
            $table->decimal('unrecorded_bank_debits', 18, 2)->default(0)->comment('débitos del banco aún no registrados en libros');
            $table->string('status')->default('in_progress')->comment('in_progress|completed');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
