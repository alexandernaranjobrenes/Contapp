<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda una combinación de parámetros de uno de los 6 reportes ya
     * existentes (identificado por report_code, un código fijo — no hay
     * tabla "reports": el motor metadata-driven de CLAUDE.md secc. 4 se
     * descartó explícitamente el 2026-08-27, ver App\Domains\Reporting\
     * Support\ReportCatalog). company_id nunca es parte de "parameters":
     * la compañía activa siempre se resuelve de la sesión al invocar.
     */
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // El creador; se borra junto con el usuario — dato de
            // conveniencia personal, no un registro contable/de auditoría
            // que deba sobrevivir a la baja de su dueño.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('report_code');
            $table->string('name');
            // Valores tal como se ingresaron, incluyendo el wrapper
            // fixed/relative de cada parámetro de fecha (ver RelativeDate).
            $table->json('parameters');
            $table->boolean('is_shared')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'report_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
