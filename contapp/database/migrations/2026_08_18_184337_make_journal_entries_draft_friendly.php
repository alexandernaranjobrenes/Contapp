<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un borrador (status='draft') todavía no tiene consecutivo interno
 * asignado (eso pasa solo al contabilizar formalmente, para no dejar
 * huecos en la numeración oficial cada vez que alguien abandona un
 * borrador) ni necesariamente un período fiscal resuelto (podría ser
 * para una fecha sin período configurado todavía, o uno cerrado que se
 * completará después). Ambas columnas ya eran opcionales en la lógica
 * de negocio; esto solo lo permite también en el esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('document_number')->nullable()->change();
            $table->foreignId('fiscal_period_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('document_number')->nullable(false)->change();
            $table->foreignId('fiscal_period_id')->nullable(false)->change();
        });
    }
};
