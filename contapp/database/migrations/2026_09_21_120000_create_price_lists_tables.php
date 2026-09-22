<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de precios: el precio de venta, que hasta ahora no existía en
 * ningún lado. Cada línea de factura se digitaba a mano, con todo lo que eso
 * implica — el mismo artículo a distinto precio según quién facture, y ningún
 * lugar donde subir un aumento.
 *
 * ── Precio y costo no se tocan ───────────────────────────────────────────
 *
 * El costo lo mantiene exclusivamente el motor de movimientos y sale de lo
 * que se pagó; el precio es una decisión comercial y no tiene ninguna
 * relación contable con él. Esta tabla NO entra al costeo, no genera asiento
 * y no participa del kardex. Se encuentran en un solo lugar: el margen, que
 * es un reporte.
 *
 * ── Una lista por moneda, sin conversión ─────────────────────────────────
 *
 * La lista se denomina en una moneda y punto. Guardar un solo precio y
 * convertirlo al tipo de cambio del día haría que el precio en dólares
 * cambiara todos los días sin que nadie lo decidiera, y que el de un catálogo
 * impreso no coincidiera con el del sistema. Una lista en colones y una en
 * dólares son dos listas distintas, cada una con su precio decidido.
 *
 * ── Vigencia ─────────────────────────────────────────────────────────────
 *
 * `valid_from` / `valid_to` permiten cargar el aumento de enero en diciembre
 * y que entre solo. Sin eso, subir precios es un trabajo que hay que hacer
 * exactamente el día que corresponde.
 *
 * ── Lo que NO hace ───────────────────────────────────────────────────────
 *
 * No hay escalas por cantidad (comprar 100 más barato que comprar 10). Es
 * real y común en mayoreo, pero duplica la resolución —hay que elegir tramo
 * antes de elegir precio— y hasta que alguien lo pida sería inventar una
 * política de descuentos que nadie configuró. La factura ya tiene descuento
 * por línea con su código de Hacienda, que cubre el caso puntual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            // La moneda es parte de la identidad de la lista, no un atributo
            // convertible: ver el encabezado.
            $table->foreignId('currency_id')->constrained();
            // Los precios se guardan CON o SIN impuesto según la lista. Un
            // catálogo al público se piensa con IVA incluido y uno mayorista
            // sin él; obligar a uno de los dos haría que alguien tuviera que
            // hacer la cuenta a mano cada vez, que es donde aparecen los
            // errores de céntimos.
            $table->boolean('prices_include_tax')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            // La lista predeterminada es la que aplica a un cliente que no
            // tiene ninguna asignada. Sin ella, dar de alta un cliente
            // dejaría sus facturas sin precio.
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            // Misma precisión que sales_document_lines.unit_price: el precio
            // que sale de acá va directo a la línea y redondearlo antes sería
            // perder centavos en artículos de bajo valor unitario.
            $table->decimal('unit_price', 18, 5);
            $table->timestamps();

            // Un artículo aparece una sola vez por lista: dos precios para lo
            // mismo no es una configuración, es un error que nadie notaría.
            $table->unique(['price_list_id', 'item_id']);
        });

        Schema::table('business_partners', function (Blueprint $table) {
            // Nullable = usa la lista predeterminada de la compañía. Es lo
            // correcto para la mayoría de clientes; asignar una lista es la
            // excepción (mayorista, distribuidor, convenio).
            $table->foreignId('price_list_id')->nullable()->after('currency_id')
                ->constrained('price_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
        });

        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
