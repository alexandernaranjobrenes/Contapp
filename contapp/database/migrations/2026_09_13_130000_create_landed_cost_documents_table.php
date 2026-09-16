<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precios de entrega / importación: flete, aranceles, seguro, agencia
 * aduanal. Llegan DESPUÉS de la mercancía y de otros proveedores, y se
 * capitalizan al costo del artículo — pero solo en la proporción que sigue
 * en existencia; lo demás ya se vendió y va a resultados
 * (docs/decisiones.md 2026-09-13 Fase 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained();
            $table->foreignId('journal_entry_id')->constrained();
            $table->foreignId('inventory_document_id')->constrained()
                ->comment('la recepción cuyo costo se está incrementando');
            $table->foreignId('business_partner_id')->constrained()
                ->comment('quien cobra el flete/arancel: transportista, agencia aduanal');
            $table->date('document_date');
            $table->date('posting_date');
            $table->decimal('amount', 18, 2)->comment('total del costo a repartir, en moneda local');
            $table->decimal('capitalized_amount', 18, 2)->comment('parte que entró al inventario todavía en existencia');
            $table->decimal('expensed_amount', 18, 2)->comment('parte cuya mercancía ya salió: va a diferencia de precio');
            $table->string('description')->nullable();
            $table->string('status')->default('posted')->comment('posted|voided');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'posting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_documents');
    }
};
