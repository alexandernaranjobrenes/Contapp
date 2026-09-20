<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlace interno de una nota de crédito con el comprobante que corrige.
 *
 * La referencia fiscal (sales_references) ya existe y es la que exige
 * Hacienda, pero es texto libre: puede apuntar a una factura de papel o a una
 * emitida antes de CONTAPP. Este enlace es otra cosa —el documento nuestro—,
 * y es lo que permite devolver la mercancía AL COSTO CON QUE SALIÓ y acreditar
 * contra la partida abierta de esa venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->foreignId('original_sales_document_id')->nullable()->after('inventory_document_id')
                ->constrained('sales_documents')->nullOnDelete()
                ->comment('comprobante que esta nota de crédito corrige; null en el resto');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_sales_document_id');
        });
    }
};
