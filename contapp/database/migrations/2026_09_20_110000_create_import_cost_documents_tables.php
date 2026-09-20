<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costos de nacionalización en dos fases.
 *
 * Fase 1 (esta tabla): cada proveedor del servicio —naviera, agencia aduanal,
 * almacén fiscal— factura su rubro y todavía no se sabe, o no se quiere
 * decidir aún, a qué importación corresponde:
 *
 *     Debe   Costos de importación por asignar   (landed_cost_clearing)
 *     Haber  Cuentas por pagar del proveedor     (abre partida)
 *
 * Fase 2 (landed_cost_documents, que ya existía): se asigna a una importación
 * concreta y el costo entra al artículo, liquidando la transitoria. Un rubro
 * puede repartirse entre varias importaciones y una importación puede recibir
 * varios rubros: por eso la asignación es su propia tabla y no una columna.
 *
 * Misma bisagra que GR/IR en el ciclo de compra: una cuenta que se debita en
 * un momento y se acredita en otro, y cuyo saldo vivo dice exactamente cuánto
 * costo de nacionalización queda sin asignar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_cost_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->comment('consecutivo interno por compañía; no es fiscal');
            $table->foreignId('document_type_id')->constrained();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_partner_id')->constrained('business_partners')
                ->comment('quién prestó el servicio de nacionalización');
            $table->string('concept', 30)
                ->comment('flete|seguro|aranceles|almacenaje|agencia|transporte_interno|otros');
            $table->date('document_date');
            $table->date('posting_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 18, 2)->comment('monto del rubro');
            $table->decimal('allocated_amount', 18, 2)->default(0)
                ->comment('ya asignado a importaciones; lo pendiente es amount - allocated_amount');
            $table->string('status', 20)->default('pending')
                ->comment('pending|partial|allocated|cancelled');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('import_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_cost_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landed_cost_document_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 2)->comment('cuánto de este rubro se asignó a esa importación');
            $table->timestamps();

            $table->index(['import_cost_document_id', 'landed_cost_document_id'], 'ica_rubro_costeo_idx');
        });

        // Un costeo financiado por rubros acumulados puede venir de varios
        // proveedores a la vez —naviera, agencia y almacén fiscal sobre el
        // mismo contenedor—, así que ya no hay UN proveedor que anotar: quién
        // prestó cada servicio vive en import_cost_documents.
        Schema::table('landed_cost_documents', function (Blueprint $table) {
            $table->foreignId('business_partner_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_cost_allocations');
        Schema::dropIfExists('import_cost_documents');

        Schema::table('landed_cost_documents', function (Blueprint $table) {
            $table->foreignId('business_partner_id')->nullable(false)->change();
        });
    }
};
