<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de precios heredada de la categoría del socio.
 *
 * El escalón del medio de la precedencia:
 *
 *     lista del cliente  →  lista de su categoría  →  predeterminada
 *
 * Nace de un problema de volumen concreto: asignarle la lista de mayoreo a
 * 400 clientes uno por uno no es configuración, es transcripción — y cuando
 * entra un cliente nuevo hay que acordarse de hacerlo otra vez. Con la
 * herencia se configura una vez en la categoría y todo el que pertenezca a
 * ella la toma.
 *
 * ── Por qué la categoría y no otra cosa ──────────────────────────────────
 *
 * `bp_categories` ya existía para agrupar reportes de ventas (mayorista,
 * gobierno, detalle), y esa clasificación es justamente la que en la
 * práctica decide qué precio se cobra. Inventar una segunda clasificación
 * paralela solo para precios obligaría a mantener las dos en sincronía a
 * mano, que es la forma más segura de que se separen.
 *
 * Eso sí, sigue siendo OPCIONAL: una categoría sin lista no cambia nada y la
 * clasificación conserva su uso original de agrupar reportes. Asignarle una
 * lista es sumarle una función, no redefinirla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bp_categories', function (Blueprint $table) {
            // Nullable = esta categoría no define precios; sus socios caen a
            // la lista predeterminada de la compañía.
            $table->foreignId('price_list_id')->nullable()->after('name')
                ->constrained('price_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bp_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
        });
    }
};
