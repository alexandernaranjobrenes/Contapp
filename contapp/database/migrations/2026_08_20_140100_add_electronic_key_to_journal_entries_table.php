<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clave numérica de Hacienda (comprobantes electrónicos): 50 dígitos,
 * única dentro de la compañía — dos asientos con la misma clave sería un
 * documento duplicado. Nula para cualquier documento que no la exija
 * (DocumentType::requires_electronic_key=false es la mayoría).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('electronic_key', 50)->nullable()->after('description');

            // Nombre explícito, mismo criterio que journal_entries_doc_number_unique:
            // más corto y predecible que el autogenerado.
            $table->unique(['company_id', 'electronic_key'], 'journal_entries_electronic_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_electronic_key_unique');
            $table->dropColumn('electronic_key');
        });
    }
};
