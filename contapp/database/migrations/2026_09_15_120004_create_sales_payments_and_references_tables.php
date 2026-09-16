<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hasta 4 medios por comprobante (Nota 6). La suma debe dar exactamente
        // el total del documento; lo hace cumplir PostSalesDocumentService.
        Schema::create('sales_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->string('method_code', 2);
            $table->decimal('amount', 18, 5);
            $table->timestamps();

            $table->index('sales_document_id');
        });

        // Obligatorio en notas de crédito/débito y facturas de compra.
        Schema::create('sales_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 2)->comment('Nota 10');
            $table->string('number', 50)->comment('clave de 50 dígitos o número del documento físico');
            $table->date('issued_at')->nullable();
            $table->string('reason_code', 2)->comment('Nota 9');
            $table->string('reason', 180);
            $table->timestamps();

            $table->index('sales_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_references');
        Schema::dropIfExists('sales_payments');
    }
};
