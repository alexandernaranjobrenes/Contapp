<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobante electrónico de venta. Un mismo registro sirve a los tres
 * propósitos del módulo: es la fuente del XML v4.4, dispara el movimiento de
 * inventario y origina el asiento contable.
 *
 * Los totales se ALMACENAN, a diferencia de casi todo el resto del sistema,
 * porque no son un saldo derivable: son parte del documento firmado y enviado
 * a Hacienda. Recalcularlos después daría un número distinto al que la DGT
 * aceptó si cambiara una tarifa o un precio de lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()
                ->comment('tipo de documento del ERP: define consecutivo interno y comportamiento contable');

            $table->string('fiscal_document_type', 2)->comment('Nota 3: 01 FE, 02 ND, 03 NC, 04 TE, 08 FEC, 09 FEE, 10 REP');
            $table->string('situation', 1)->default('1')->comment('1 normal, 2 contingencia, 3 sin internet');

            $table->string('branch', 3)->default('001');
            $table->string('terminal', 5)->default('00001');
            $table->string('consecutive', 20)->nullable()->comment('consecutivo fiscal de 20 dígitos');
            $table->string('clave', 50)->nullable()->comment('clave numérica de 50 dígitos');
            $table->string('security_code', 8)->nullable()->comment('dígitos 43-50 de la clave');

            $table->string('emitter_activity_code', 6)->nullable();
            $table->string('receiver_activity_code', 6)->nullable();

            $table->foreignId('business_partner_id')->nullable()->constrained()->nullOnDelete()
                ->comment('null en tiquete electrónico a consumidor final');

            $table->foreignId('currency_id')->constrained();
            $table->decimal('exchange_rate', 18, 5)->default(1)->comment('1.00000 cuando la moneda es la local');

            $table->string('sale_condition', 2)->comment('Nota 5');
            $table->unsignedSmallInteger('credit_term_days')->nullable()->comment('obligatorio en condiciones a crédito');

            $table->date('document_date');
            $table->date('posting_date')->comment('fecha rectora del asiento');
            $table->date('due_date')->nullable();

            // Resumen exigido por el XML. Todos en la moneda del documento.
            $table->decimal('total_taxed_services', 18, 5)->default(0);
            $table->decimal('total_exempt_services', 18, 5)->default(0);
            $table->decimal('total_exonerated_services', 18, 5)->default(0);
            $table->decimal('total_no_subject_services', 18, 5)->default(0);
            $table->decimal('total_taxed_goods', 18, 5)->default(0);
            $table->decimal('total_exempt_goods', 18, 5)->default(0);
            $table->decimal('total_exonerated_goods', 18, 5)->default(0);
            $table->decimal('total_no_subject_goods', 18, 5)->default(0);
            $table->decimal('total_sale', 18, 5)->default(0);
            $table->decimal('total_discounts', 18, 5)->default(0);
            $table->decimal('total_net_sale', 18, 5)->default(0);
            $table->decimal('total_tax', 18, 5)->default(0);
            $table->decimal('total_document', 18, 5)->default(0);

            $table->string('notes')->nullable();

            $table->string('status')->default('draft')
                ->comment('draft|posted|voided — el ciclo fiscal (firmado/enviado/aceptado) llega con el XML');

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete()
                ->comment('asiento de la venta; null mientras es borrador');
            $table->foreignId('inventory_document_id')->nullable()->constrained()->nullOnDelete()
                ->comment('salida de inventario con su costo de ventas; null si el documento no mueve stock');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'clave'], 'sales_documents_clave_unique');
            $table->unique(['company_id', 'consecutive'], 'sales_documents_consecutive_unique');
            $table->index(['company_id', 'posting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_documents');
    }
};
