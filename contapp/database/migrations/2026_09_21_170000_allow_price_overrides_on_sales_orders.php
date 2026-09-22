<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El control de precio se adelanta al pedido de venta.
 *
 * Antes solo cubría la factura, y eso dejaba la autorización más tarde de lo
 * ideal: el precio se pacta al tomar el pedido, no al facturarlo. Un
 * vendedor podía comprometer un precio por escrito con el cliente y el
 * sistema se enteraba recién semanas después, cuando ya había que cumplirlo.
 *
 * ── La autorización del pedido viaja a su factura ────────────────────────
 *
 * Es la razón de que valga la pena hacerlo acá y no duplicar el trámite: si
 * el precio ya se autorizó en el pedido, la factura que lo cumple NO vuelve
 * a pedir la firma para el mismo artículo al mismo precio. Pedirla dos veces
 * convertiría el control en un estorbo y la gente buscaría cómo saltárselo.
 *
 * Cambiar el precio otra vez al facturar sí es un desvío nuevo, y ese sí se
 * autoriza aparte.
 *
 * ── Uno de los dos, nunca los dos ────────────────────────────────────────
 *
 * Una autorización pertenece a un pedido O a una factura. Se resuelve con
 * dos columnas nullable y no con una relación polimórfica: son dos, se
 * conocen de antemano, y las claves foráneas reales valen más acá que la
 * generalidad — con morphTo la base de datos no podría garantizar que el id
 * apunte a algo que existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_override_authorizations', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('sales_document_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->after('sales_document_line_id')
                ->constrained('sales_order_lines')->nullOnDelete();
        });

        // sales_document_id deja de ser obligatorio: una autorización de
        // pedido todavía no tiene factura, y puede que nunca la tenga si el
        // pedido se cancela.
        Schema::table('price_override_authorizations', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_document_id')->nullable()->change();
        });

        Schema::table('price_override_authorizations', function (Blueprint $table) {
            // La consulta de la factura: "¿este pedido ya trae autorizado
            // este artículo?".
            $table->index(['sales_order_id', 'item_id'], 'price_override_order_item_index');
        });
    }

    public function down(): void
    {
        Schema::table('price_override_authorizations', function (Blueprint $table) {
            $table->dropIndex('price_override_order_item_index');
            $table->dropConstrainedForeignId('sales_order_line_id');
            $table->dropConstrainedForeignId('sales_order_id');
        });
    }
};
