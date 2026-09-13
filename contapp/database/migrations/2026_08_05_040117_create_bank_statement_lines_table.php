<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->date('statement_date');
            $table->string('description')->nullable();
            $table->decimal('amount', 18, 2)->comment('positivo = crédito del banco, negativo = débito del banco');
            $table->string('import_batch_id')->nullable();
            $table->boolean('matched')->default(false);
            $table->timestamps();

            $table->index(['bank_account_id', 'matched']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
