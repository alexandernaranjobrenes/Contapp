<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 (compras): una entrada por compra lleva proveedor, y queda
 * pendiente de facturar hasta que una factura la liquide. No hace falta una
 * tabla de facturas de proveedor: la factura ES un asiento contable (no mueve
 * stock), y basta con que la recepción apunte a él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->foreignId('business_partner_id')->nullable()->after('operation')
                ->constrained()->nullOnDelete()
                ->comment('proveedor; obligatorio en purchase_receipt, null en el resto');
            $table->foreignId('invoice_journal_entry_id')->nullable()->after('journal_entry_id')
                ->constrained('journal_entries')->nullOnDelete()
                ->comment('asiento de la factura que liquidó la cuenta puente GR/IR; null = pendiente de facturar');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_partner_id');
            $table->dropConstrainedForeignId('invoice_journal_entry_id');
        });
    }
};
