<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nivel de reorden en la FICHA del artículo, como valor por defecto.
 *
 * ── Por qué por defecto y no un segundo campo suelto ─────────────────────
 *
 * El mínimo ya existía por almacén (item_warehouses), que es donde de verdad
 * se decide: la bodega principal necesita 100 y la de tránsito 0. Pero
 * configurarlo almacén por almacén es tedioso y, en la mayoría de empresas
 * con un solo almacén, absurdo.
 *
 * Tener el dato en los dos lados sin más sería dos fuentes de verdad y la
 * pregunta inevitable de cuál manda. Se resolvió con PRECEDENCIA, que es el
 * patrón que este módulo ya usa en la matriz de determinación de cuentas
 * (artículo > grupo > almacén > compañía):
 *
 *     mínimo efectivo = item_warehouses.minimum_stock  (si está definido)
 *                       ?? items.minimum_stock          (el de la ficha)
 *
 * ── El cambio que lo hace posible: nullable ──────────────────────────────
 *
 * `item_warehouses.minimum_stock` pasa de NOT NULL DEFAULT 0 a nullable,
 * porque hay que poder distinguir tres estados que antes se confundían en
 * uno:
 *
 *   null → "este almacén no define nada": hereda el de la ficha
 *   0    → "este almacén NO lleva control de reorden", aunque la ficha sí
 *   >0   → sobrescribe el de la ficha
 *
 * Sin esa distinción, poner 0 en un almacén de tránsito para excluirlo sería
 * indistinguible de no haberlo configurado, y heredaría el mínimo del
 * artículo — pidiendo reponer una bodega que existe justamente para estar
 * vacía.
 *
 * Los ceros que ya existen se convierten a null: con el esquema viejo 0 era
 * el default de la columna y significaba "sin configurar", no una decisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('minimum_stock', 18, 6)->default(0)->after('tracks_lots')
                ->comment('mínimo por defecto del artículo; cada almacén puede sobrescribirlo');
            $table->decimal('maximum_stock', 18, 6)->nullable()->after('minimum_stock')
                ->comment('máximo por defecto; null = reponer solo hasta el mínimo');
        });

        // El orden importa: primero se permite el null, después se convierten
        // los ceros heredados. Al revés, el UPDATE fallaría contra NOT NULL.
        Schema::table('item_warehouses', function (Blueprint $table) {
            $table->decimal('minimum_stock', 18, 6)->nullable()->default(null)->change();
        });

        DB::table('item_warehouses')->where('minimum_stock', 0)->update(['minimum_stock' => null]);
    }

    public function down(): void
    {
        DB::table('item_warehouses')->whereNull('minimum_stock')->update(['minimum_stock' => 0]);

        Schema::table('item_warehouses', function (Blueprint $table) {
            $table->decimal('minimum_stock', 18, 6)->default(0)->nullable(false)->change();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['minimum_stock', 'maximum_stock']);
        });
    }
};
