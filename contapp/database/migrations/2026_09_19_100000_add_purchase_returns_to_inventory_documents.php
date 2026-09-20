<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devolución al proveedor (nota de crédito de compra).
 *
 * Es el espejo exacto de la compra y por eso reutiliza su misma estructura sin
 * inventar tablas: la recepción usa `journal_entry_id` para el asiento de
 * stock e `invoice_journal_entry_id` para el de la factura; la devolución usa
 * `journal_entry_id` para la salida de mercancía e `invoice_journal_entry_id`
 * para el de la nota de crédito. La cuenta puente GR/IR vuelve a hacer de
 * bisagra entre ambos pasos, igual que en la compra.
 *
 * `source_document_id` apunta a la recepción que se está devolviendo, que es
 * de donde salen el precio original y el límite de cuánto se puede devolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->foreignId('source_document_id')->nullable()->after('production_order_id')
                ->constrained('inventory_documents')->nullOnDelete()
                ->comment('recepción de origen; solo en devoluciones al proveedor');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_document_id');
        });
    }
};
