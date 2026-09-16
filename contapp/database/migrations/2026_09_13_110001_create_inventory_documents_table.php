<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained();
            // NOT NULL a propósito: no existe movimiento de stock sin su
            // asiento. Es la regla que obliga a que el inventario cuadre
            // siempre contra el mayor general.
            $table->foreignId('journal_entry_id')->constrained();
            $table->string('operation')->comment('goods_receipt|goods_issue|count_adjustment');
            $table->date('document_date');
            $table->date('posting_date')->comment('fecha rectora, la misma del asiento');
            $table->string('description')->nullable();
            $table->string('status')->default('posted')->comment('posted|voided');
            $table->foreignId('reversal_of_id')->nullable()->constrained('inventory_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'posting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_documents');
    }
};
