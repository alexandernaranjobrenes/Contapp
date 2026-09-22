<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Números de serie: qué unidad exacta entró y a quién salió. Tercera capa de
 * trazabilidad, después de las ubicaciones (Fase 7) y los lotes (Fase 8), y
 * como ellas NO toca el costeo: el costo sigue siendo promedio ponderado
 * móvil global por artículo y el motor de promedios no mira esta tabla nunca.
 *
 * ── La diferencia con un lote, que define todo el diseño ─────────────────
 *
 * Un lote es un BALDE: tiene cantidad, se reparte entre almacenes y
 * ubicaciones, y por eso necesita su propia tabla de existencias
 * (item_lot_stock). Una serie es una UNIDAD: no tiene cantidad, tiene
 * UBICACIÓN Y ESTADO. Por eso acá no hay tabla de existencia por serie — la
 * fila misma es la unidad, y dónde está lo dicen sus columnas.
 *
 * De ahí sale la regla central: en una línea de un artículo serializado, la
 * cantidad de series indicadas tiene que ser EXACTAMENTE igual a la
 * cantidad. No es una validación cosmética; es lo que hace que el conteo de
 * series en existencia cuadre con item_warehouses.on_hand, que es el
 * invariante que esta fase agrega y que tiene su propia prueba.
 *
 * ── NIC 2 §23 y por qué el costeo igual no cambia ────────────────────────
 *
 * §23 exige identificación específica para bienes que NO son ordinariamente
 * intercambiables, y un artículo serializado es a menudo de esos. Cambiar el
 * motor de costeo para esos artículos es una decisión grande y separada —otro
 * motor, no una columna más acá— y este diseño deliberadamente no la toma.
 * Es el mismo criterio que ya dejó escrito la migración de lotes. Lo que sí
 * queda es la trazabilidad completa, que es lo que se pide en la práctica
 * para garantías y recalls.
 *
 * ── Qué se puede serializar ──────────────────────────────────────────────
 *
 * Es propiedad del ARTÍCULO (items.tracks_serials), igual que los lotes: un
 * electrodoméstico lleva serie en todos los almacenes y un tornillo en
 * ninguno. Serie y lote conviven: una serie puede pertenecer a un lote (el
 * equipo X de la tanda de fabricación Y).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('tracks_serials')->default(false)->after('tracks_lots');
        });

        Schema::create('item_serials', function (Blueprint $table) {
            $table->id();
            // Cuelga de item_id y no lleva company_id: el CompanyScope de
            // Item lo aísla, mismo criterio que item_lots y warehouse_bins.
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number', 80);

            // El estado y la ubicación SON la existencia de esta unidad. No
            // hay tabla aparte porque no hay cantidad que repartir.
            //   in_stock  → está en el almacén que dice warehouse_id
            //   issued    → salió (vendida, consumida, dada de baja)
            //   scrapped  → se destruyó; no vuelve
            $table->string('status', 20)->default('in_stock');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_bin_id')->nullable()->constrained()->nullOnDelete();
            // Serie y lote conviven: el equipo X de la tanda Y.
            $table->foreignId('item_lot_id')->nullable()->constrained()->nullOnDelete();

            // La trazabilidad que justifica la tabla: de qué documento entró
            // y en cuál salió. Con esto se contesta "¿a quién le vendimos la
            // serie 4471?", que es la pregunta de una garantía o un recall.
            $table->foreignId('received_document_id')->nullable()
                ->constrained('inventory_documents')->nullOnDelete();
            $table->foreignId('issued_document_id')->nullable()
                ->constrained('inventory_documents')->nullOnDelete();
            $table->date('received_at')->nullable();
            $table->date('issued_at')->nullable();
            // Fin de garantía de ESTA unidad. Es el dato por el que se
            // consulta una serie en el mostrador.
            $table->date('warranty_until')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            // Una serie es única POR ARTÍCULO y no por compañía: dos
            // fabricantes distintos pueden usar la misma numeración, y
            // exigir unicidad global rechazaría un alta legítima.
            $table->unique(['item_id', 'serial_number']);
            // El conteo de series en existencia por almacén es la consulta
            // del invariante (series en stock == on_hand) y la de la
            // pantalla; sin índice barre todo el maestro.
            $table->index(['item_id', 'status', 'warehouse_id'], 'item_serials_stock_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_serials');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('tracks_serials');
        });
    }
};
