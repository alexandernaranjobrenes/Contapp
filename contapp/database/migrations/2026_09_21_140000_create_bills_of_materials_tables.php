<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de materiales: qué lleva un producto y cuánto. Hasta ahora la orden
 * de fabricación existía pero cada emisión se digitaba a mano, recordando de
 * memoria los componentes — con el resultado previsible de que se olvida uno
 * y la orden cierra con desviación sin que nadie sepa por qué.
 *
 * ── Es una receta, no un costo ───────────────────────────────────────────
 *
 * La misma frontera que respetan las listas de precios y los lotes: esta
 * tabla dice QUÉ y CUÁNTO, nunca a qué costo. El costo lo pone el motor de
 * movimientos cuando la emisión se contabiliza, al promedio vigente de cada
 * componente. Guardar un costo acá sería costeo estándar, que es otro
 * sistema —con sus variaciones de precio y de uso, y su propia discusión
 * contable— y no una columna más.
 *
 * ── Por qué la receta es por LOTE y no por unidad ────────────────────────
 *
 * `output_quantity` guarda cuántas unidades produce la receta completa. Una
 * fórmula que rinde 100 litros con 3,2 kg de un insumo NO es lo mismo que
 * 0,032 kg por litro: dividir y volver a multiplicar arrastra redondeo, y en
 * química o alimentos esa diferencia se acumula lote tras lote. Se guarda
 * como está escrita la fórmula y se escala al explotar.
 *
 * ── Lo que NO hace ───────────────────────────────────────────────────────
 *
 * La explosión es de UN NIVEL: al emitir se consumen los componentes tal
 * como están, y un subensamble se consume como el artículo que es — que es
 * como funciona en la planta, porque el subensamble ya se fabricó y está en
 * bodega. Lo que sí atraviesa todos los niveles es la detección de ciclos:
 * una receta que se contiene a sí misma, aunque sea a tres niveles de
 * distancia, no describe nada fabricable.
 *
 * Tampoco hay rutas ni centros de trabajo (tiempos, máquinas, mano de obra):
 * eso es planificación de producción, no inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills_of_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // El producto terminado que produce esta receta.
            $table->foreignId('item_id')->constrained();
            $table->string('code', 20);
            $table->string('name');
            // Cuántas unidades del producto rinde la receta completa: ver el
            // encabezado sobre por qué no se guarda por unidad.
            $table->decimal('output_quantity', 18, 6)->default(1);
            // Varias recetas para el mismo producto son legítimas —fórmula de
            // verano y de invierno, presentación de 1 L y de 5 L— y la
            // predeterminada es la que se ofrece al crear la orden.
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'item_id', 'status']);
        });

        Schema::create('bill_of_material_lines', function (Blueprint $table) {
            $table->id();
            // Tabla explícita por lo mismo que abajo: el plural de
            // "bills_of_materials" va en la primera palabra y Laravel no lo
            // infiere desde el nombre de la columna.
            $table->foreignId('bill_of_material_id')->constrained('bills_of_materials')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('items');
            // Cantidad por LOTE (por output_quantity unidades), no por unidad.
            $table->decimal('quantity', 18, 6);
            // Merma esperada: si de cada 100 g se pierden 5 en el proceso,
            // hay que emitir 105 para que queden 100. Es un dato real de
            // planta y sin él la orden cierra con desviación sistemática que
            // parece un error y no lo es.
            $table->decimal('scrap_percentage', 8, 4)->default(0);
            // De qué almacén sale normalmente este componente. Nullable: si
            // no se fija, lo elige quien emite.
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            // Un componente aparece una sola vez por receta: dos líneas del
            // mismo insumo no es una configuración, es una duplicación que
            // nadie notaría hasta que se emite el doble.
            $table->unique(['bill_of_material_id', 'component_item_id'], 'bom_line_unique');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            // Con qué receta se armó la orden. Nullable: las órdenes
            // anteriores a esta fase no tienen ninguna, y una orden puntual
            // sin receta sigue siendo válida.
            // La tabla se nombra explícita: Laravel la inferiría como
            // "bill_of_materials" desde el nombre de la columna, y la tabla
            // real es "bills_of_materials" (el plural va en la primera
            // palabra, como en inglés).
            $table->foreignId('bill_of_material_id')->nullable()->after('item_id')
                ->constrained('bills_of_materials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bill_of_material_id');
        });

        Schema::dropIfExists('bill_of_material_lines');
        Schema::dropIfExists('bills_of_materials');
    }
};
