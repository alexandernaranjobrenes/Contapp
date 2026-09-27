<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De cuál liquidación salió un movimiento de vacaciones.
 *
 * ── No es trazabilidad decorativa: es lo que permite anular ──────────────
 *
 * Contabilizar una liquidación descarga del control los días de vacaciones
 * que se pagaron. Si esa liquidación se anula, hay que devolverlos — y para
 * devolver exactamente esos días hay que saber cuáles fueron.
 *
 * Sin esta columna, anular tendría que adivinar: buscar el último movimiento
 * de tipo liquidación del trabajador y suponer que es el correcto. Con dos
 * liquidaciones anuladas y rehechas —que pasa cuando una salida se corrige—
 * la suposición falla y el trabajador queda con días que no tiene o sin días
 * que sí tiene.
 *
 * Los movimientos ya llevan `payroll_period_id` y `payroll_entry_id` por la
 * misma razón: cada rebajo de saldo apunta al documento que lo causó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacation_movements', function (Blueprint $table) {
            $table->foreignId('labor_settlement_id')->nullable()->after('payroll_entry_id')
                ->constrained('labor_settlements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vacation_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('labor_settlement_id');
        });
    }
};
