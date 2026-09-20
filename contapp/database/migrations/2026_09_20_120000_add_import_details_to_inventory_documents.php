<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identifica una entrada como importación.
 *
 * Hasta acá una importación era, para el sistema, indistinguible de una
 * compra local: solo una entrada a la que alguien le cargaba flete. Eso hacía
 * imposible listar "importaciones pendientes de liquidar" y no impedía
 * asignarle costos de nacionalización a una compra hecha en San José.
 *
 * `is_import` es la marca; el resto son los datos con los que la importación
 * se identifica en la vida real y que hoy vivían en la cabeza del encargado o
 * en el campo de descripción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->boolean('is_import')->default(false)->after('business_partner_id')
                ->comment('solo una entrada por compra puede serlo; habilita el costeo de nacionalización');
            $table->string('customs_declaration', 40)->nullable()->after('is_import')
                ->comment('número de DUA (Declaración Única Aduanera)');
            $table->string('customs_office', 30)->nullable()->after('customs_declaration')
                ->comment('aduana por la que ingresó');
            $table->string('transport_document', 60)->nullable()->after('customs_office')
                ->comment('conocimiento de embarque, guía aérea o carta de porte');
            $table->string('origin_country', 60)->nullable()->after('transport_document');
            $table->date('customs_date')->nullable()->after('origin_country')
                ->comment('fecha del DUA; puede diferir de la de recepción');

            $table->index(['company_id', 'is_import']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_import']);
            $table->dropColumn([
                'is_import', 'customs_declaration', 'customs_office',
                'transport_document', 'origin_country', 'customs_date',
            ]);
        });
    }
};
