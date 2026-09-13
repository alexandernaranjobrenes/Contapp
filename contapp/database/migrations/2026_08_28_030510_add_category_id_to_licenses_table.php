<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            // Nullable: licencias emitidas antes de que existiera el catálogo
            // de categorías siguen con su max_companies propio, sin romperse.
            // nullOnDelete: borrar una categoría nunca debe borrar ni bloquear
            // la licencia que ya la usó — solo pierde la referencia/etiqueta.
            $table->foreignId('category_id')->nullable()->after('code')
                ->constrained('license_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
