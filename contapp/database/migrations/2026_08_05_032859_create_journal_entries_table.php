<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained();
            $table->unsignedBigInteger('document_number');
            $table->date('document_date');
            $table->foreignId('fiscal_period_id')->constrained();
            $table->string('description')->nullable();
            $table->string('status')->default('draft')->comment('draft|posted|voided');
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('source_module')->nullable();
            $table->unsignedBigInteger('business_partner_id')->nullable()
                ->comment('FK a business_partners se agrega en la migración de Fase 1 (CxC/CxP)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Nombre explícito: el auto-generado supera el límite de 64
            // caracteres de MySQL para identificadores.
            $table->unique(['company_id', 'document_type_id', 'document_number'], 'journal_entries_doc_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
