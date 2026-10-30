<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El Panel de cada compañía (CLAUDE.md secc. 44): qué elementos lleva y en
 * qué orden. Una fila por compañía; sin fila, el Panel sale con los
 * elementos de siempre (DashboardWidgetCatalog::DEFAULT_LAYOUT).
 *
 * `widgets` es la lista de claves del catálogo, en orden. Cada persona ve
 * solo los elementos de las pantallas a las que tiene acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('widgets');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
