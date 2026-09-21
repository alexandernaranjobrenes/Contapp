<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orden de compra: el espejo de la orden de pedido del lado de compras, y la
 * pieza que cerraba la asimetría del módulo — ventas tenía pedido → reserva →
 * factura, y compras arrancaba directo en la recepción.
 *
 * Igual que la orden de pedido, una OC NO genera asiento ni consecutivo
 * fiscal: es un compromiso, no un hecho económico. Todavía no hay mercancía
 * ni pasivo; eso llega con la entrada por compra (GR/IR) y su factura.
 *
 * ── La asimetría con `reserved`, que es deliberada ───────────────────────
 *
 * `reserved` RESTRINGE: lo apartado por un pedido no se le puede vender a
 * otro, así que toda salida valida contra lo libre. `ordered` INFORMA: saber
 * que vienen 100 unidades en camino no cambia lo que se puede hacer hoy con
 * las que hay. Por eso agregar esta columna no obliga a tocar ni una sola
 * ruta de validación existente — a diferencia de `reserved`, que obligó a
 * actualizar hasta los traslados.
 *
 * Su consumidor natural es el punto de reorden: lo que hay que comprar es
 * "mínimo − (existencia − apartado + en camino)". Sin `ordered`, ese cálculo
 * mandaría a comprar de nuevo algo que ya se pidió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->comment('consecutivo interno por compañía; no es fiscal');
            $table->foreignId('business_partner_id')->constrained('business_partners');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 20)->default('open')
                ->comment('open|partially_received|received|cancelled|closed');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->decimal('quantity', 18, 6);
            $table->decimal('quantity_received', 18, 6)->default(0)
                ->comment('lo ya recibido; el pendiente vivo es quantity - quantity_received');
            // Informativo, igual que unit_price en la orden de pedido: el
            // costo real al que entra la mercancía lo fija la recepción.
            $table->decimal('unit_cost_local', 18, 6)->default(0)
                ->comment('costo pactado, informativo: la recepción manda');
            $table->string('description')->nullable();

            $table->index(['item_id', 'warehouse_id']);
        });

        Schema::table('item_warehouses', function (Blueprint $table) {
            $table->decimal('ordered', 18, 6)->default(0)->after('reserved')
                ->comment('pendiente de recibir por órdenes de compra abiertas; informativo, no restringe');
        });

        // Enlace de la recepción con la orden que la originó. Nullable: una
        // entrada por compra sigue pudiendo existir sin OC previa — no toda
        // compra pasa por una orden formal, y obligarlo rompería el flujo que
        // ya funciona.
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('source_document_id')
                ->constrained('purchase_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::table('item_warehouses', function (Blueprint $table) {
            $table->dropColumn('ordered');
        });

        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
