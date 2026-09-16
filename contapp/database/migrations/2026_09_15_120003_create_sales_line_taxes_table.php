<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una línea puede llevar varios impuestos (IVA + selectivo de consumo, por
 * ejemplo), y cada uno puede venir exonerado en parte. Por eso es tabla y no
 * columnas de la línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_line_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_line_id')->constrained()->cascadeOnDelete();

            $table->string('tax_code', 2)->comment('Nota 8: 01 IVA, 02 selectivo, 07 cálculo especial...');
            $table->string('iva_rate_code', 2)->nullable()->comment('Nota 8.1; solo cuando tax_code es de IVA');
            $table->decimal('rate_percentage', 5, 2);
            $table->decimal('taxable_base', 18, 5);
            $table->decimal('amount', 18, 5)->comment('base × tarifa, antes de exoneración');

            // Sub-nodo Exoneracion del XML. Null cuando la línea no viene
            // exonerada, que es el caso normal.
            $table->string('exoneration_document_type', 2)->nullable()->comment('Nota 10.1');
            $table->string('exoneration_document_number', 40)->nullable();
            $table->string('exoneration_article', 10)->nullable();
            $table->string('exoneration_clause', 10)->nullable();
            $table->string('exoneration_institution', 2)->nullable()->comment('Nota 23');
            $table->date('exoneration_date')->nullable();
            $table->decimal('exonerated_percentage', 5, 2)->nullable();
            $table->decimal('exonerated_amount', 18, 5)->default(0);

            $table->decimal('net_amount', 18, 5)->comment('amount − exonerated_amount: lo que de verdad se cobra');

            $table->timestamps();

            $table->index('sales_document_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_line_taxes');
    }
};
