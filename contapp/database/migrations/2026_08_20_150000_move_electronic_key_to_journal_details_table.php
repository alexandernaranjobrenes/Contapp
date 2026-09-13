<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección de diseño: un mismo asiento puede juntar varias facturas de
 * compra (una línea de gasto + su IVA por cada una, contra una sola cuenta
 * de pago) — la clave numérica de Hacienda identifica UN comprobante, así
 * que pertenece a la línea que representa esa factura, no al asiento
 * completo. electronic_key se mueve de journal_entries a journal_details.
 *
 * No lleva índice único a nivel de base de datos (journal_details no tiene
 * company_id propio — se filtra vía journal_entry_id, ver el modelo — y
 * agregar uno redundante solo para esto rompería ese criterio ya
 * establecido); la unicidad por compañía se valida en
 * JournalEntryController, igual que ya se hace con otras referencias
 * cruzadas de este mismo formulario (business_partner_id, cost_center_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_electronic_key_unique');
            $table->dropColumn('electronic_key');
        });

        Schema::table('journal_details', function (Blueprint $table) {
            $table->string('electronic_key', 50)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('journal_details', function (Blueprint $table) {
            $table->dropColumn('electronic_key');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('electronic_key', 50)->nullable()->after('description');
            $table->unique(['company_id', 'electronic_key'], 'journal_entries_electronic_key_unique');
        });
    }
};
