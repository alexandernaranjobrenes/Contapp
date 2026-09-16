<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');

            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete()
                ->comment('null permite facturar un concepto libre que no está en el maestro');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete()
                ->comment('de dónde sale la mercancía; null en servicios');
            $table->foreignId('warehouse_bin_id')->nullable()->constrained()->nullOnDelete();

            // Se copian del maestro al facturar y NO se vuelven a leer de ahí:
            // el comprobante debe poder reconstruirse tal cual se envió aunque
            // el artículo cambie de CAByS o de descripción después.
            $table->string('item_code')->nullable()->comment('código comercial');
            $table->string('cabys_code', 13);
            $table->string('description', 200);
            $table->string('unit_code', 15)->comment('Nota 15');
            $table->boolean('is_service')->default(false)->comment('separa servicios de mercancías en el resumen');

            $table->decimal('quantity', 16, 3);
            $table->decimal('unit_price', 18, 5);
            $table->decimal('total_amount', 18, 5)->comment('cantidad × precio unitario');

            $table->string('discount_code', 2)->nullable()->comment('Nota 20');
            $table->string('discount_reason', 80)->nullable()->comment('obligatorio si el código es 99');
            $table->decimal('discount_amount', 18, 5)->default(0);

            $table->decimal('subtotal', 18, 5)->comment('total_amount − discount_amount');
            $table->decimal('tax_amount', 18, 5)->default(0);
            $table->decimal('exonerated_amount', 18, 5)->default(0);
            $table->decimal('line_total', 18, 5)->comment('subtotal + impuesto neto de exoneración');

            $table->string('vin_or_serial', 17)->nullable()
                ->comment('obligatorio si el CAByS es vehículo, aeronave o embarcación');

            $table->timestamps();

            $table->index(['sales_document_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_lines');
    }
};
