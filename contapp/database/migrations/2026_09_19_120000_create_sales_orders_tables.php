<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orden de pedido: el compromiso con el cliente ANTES de la factura.
 *
 * No tiene asiento ni consecutivo fiscal —no es un hecho económico, es una
 * promesa— pero sí aparta mercancía: lo que está prometido no se le puede
 * vender a otro. Esa reserva vive en item_warehouses.reserved, al lado de la
 * existencia, porque es la misma pregunta desde dos ángulos: cuánto hay y
 * cuánto de eso ya tiene dueño.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->comment('consecutivo interno por compañía; no es fiscal');
            $table->foreignId('business_partner_id')->constrained('business_partners');
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->string('status', 20)->default('open')->comment('open|invoiced|cancelled');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->decimal('quantity', 18, 6);
            $table->decimal('quantity_invoiced', 18, 6)->default(0)
                ->comment('lo ya facturado; la reserva viva es quantity - quantity_invoiced');
            $table->decimal('unit_price', 18, 5)->default(0)
                ->comment('precio pactado, informativo: la factura manda');
            $table->string('description')->nullable();

            $table->index(['item_id', 'warehouse_id']);
        });

        Schema::table('item_warehouses', function (Blueprint $table) {
            $table->decimal('reserved', 18, 6)->default(0)->after('on_hand')
                ->comment('apartado por órdenes de pedido abiertas; disponible = on_hand - reserved');
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('original_sales_document_id')
                ->constrained('sales_orders')->nullOnDelete()
                ->comment('pedido que esta factura cumple; null en una venta sin pedido previo');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_id');
        });

        Schema::table('item_warehouses', function (Blueprint $table) {
            $table->dropColumn('reserved');
        });

        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
