<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_detail_id')->constrained('journal_details');
            $table->boolean('matched_in_books')->default(false)->comment('columna "Cta" del legado');
            $table->boolean('matched_in_bank')->default(false)->comment('columna "Bco" del legado');
            $table->foreignId('bank_statement_line_id')->nullable()->constrained('bank_statement_lines')->nullOnDelete();
            $table->timestamps();

            // Nombre explícito: el auto-generado supera el límite de 64
            // caracteres de MySQL para identificadores.
            $table->unique(['bank_reconciliation_id', 'journal_detail_id'], 'bank_reconciliation_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
    }
};
