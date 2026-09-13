<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completa el par que ya existía a medias: 'reference_document' (el
     * número del documento fuente de ESTA línea, ej. la factura #4521 de un
     * proveedor) ya vivía en la tabla y en PostJournalService, pero nunca se
     * expuso en el formulario ni en los reportes porque le faltaba su fecha
     * — un asiento que junta varias facturas de compra (mismo patrón que
     * electronic_key, ver JournalEntries/Create.vue) necesita, por línea, no
     * solo el número del documento sino también SU fecha, distinta de la
     * fecha de contabilización del asiento completo.
     */
    public function up(): void
    {
        Schema::table('journal_details', function (Blueprint $table) {
            $table->date('reference_document_date')->nullable()->after('reference_document');
        });
    }

    public function down(): void
    {
        Schema::table('journal_details', function (Blueprint $table) {
            $table->dropColumn('reference_document_date');
        });
    }
};
