<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toma física de inventario.
 *
 * El documento existe porque un conteo es un PROCESO, no un instante: se
 * define un corte, se imprime la hoja, alguien cuenta con papel en mano, y
 * recién después se registran las diferencias. Entre el corte y el ajuste
 * pasan horas o días, y lo que se contó hay que poder compararlo contra lo
 * que el sistema decía EN ESE MOMENTO — no contra lo que dice hoy.
 *
 * Por eso cada línea guarda la existencia teórica congelada al corte. El
 * ajuste en sí no estrena nada: lo genera la operación count_adjustment que
 * ya existe en PostStockMovementService, que sigue siendo el único dueño del
 * kardex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->comment('consecutivo interno por compañía; no es fiscal');
            $table->foreignId('document_type_id')->constrained()
                ->comment('con el que se contabilizará el ajuste al cerrar');
            $table->date('cutoff_date')->comment('fecha a la que se congeló la existencia teórica');
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('item_group_id')->nullable()->constrained('item_groups')->nullOnDelete()
                ->comment('familia contada; null = todas');
            $table->boolean('blind')->default(false)
                ->comment('conteo a ciegas: la hoja impresa oculta la existencia teórica');
            $table->string('status', 20)->default('open')->comment('open|posted|cancelled');
            $table->foreignId('inventory_document_id')->nullable()->constrained()->nullOnDelete()
                ->comment('ajuste generado al cerrar; null si no hubo diferencias');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('warehouse_bin_id')->nullable()->constrained('warehouse_bins')->nullOnDelete();
            $table->decimal('theoretical_quantity', 18, 6)
                ->comment('lo que el kardex decía a la fecha de corte; congelado al abrir');
            $table->decimal('counted_quantity', 18, 6)->nullable()
                ->comment('lo que se contó; null mientras la línea siga sin contar');
            $table->decimal('unit_cost_local', 18, 6)->default(0)
                ->comment('costo promedio al abrir, para valorar la diferencia en la hoja');
            $table->string('description')->nullable();

            $table->index(['stock_count_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
