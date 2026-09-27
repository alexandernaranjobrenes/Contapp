<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué promedio se pagan las vacaciones en una liquidación.
 *
 * ── Dos criterios, los dos usados en Costa Rica ──────────────────────────
 *
 * PRACTICA (el que indicó el usuario, y el valor por defecto)
 *
 *   Pago semanal ............ promedio diario de las últimas 2 SEMANAS
 *   Pago quincenal o mensual  promedio de los últimos 6 MESES, la misma
 *                             ventana del preaviso y la cesantía
 *
 *   Es práctica aceptada por el Ministerio de Trabajo: no perjudica al
 *   trabajador y hace que la liquidación se calcule con una sola base en vez
 *   de tres, lo cual además la vuelve verificable de un vistazo.
 *
 * LEGAL_50_WEEKS (el texto literal del CT art. 157)
 *
 *   Promedio de los salarios ordinarios y extraordinarios de las últimas 50
 *   SEMANAS anteriores a la fecha en que se disfrutan las vacaciones.
 *
 * ── Por qué se guarda como parámetro y no se elige en el código ──────────
 *
 * Porque las dos ventanas dan números distintos y cuál usar es una decisión
 * de la empresa, no del motor. Y porque hay un caso —poco frecuente, pero
 * real— en que la práctica queda POR DEBAJO de la ley: un trabajador que
 * tuvo horas extra o comisiones fuertes hace ocho meses y ninguna en los
 * últimos seis tiene un promedio de 50 semanas más alto que el de 6 meses.
 * Ahí la práctica sí lo perjudica, y el artículo 157 es un mínimo
 * irrenunciable.
 *
 * Por eso el cálculo no se limita a aplicar el criterio configurado: cuando
 * hay historial para las dos ventanas calcula ambas y AVISA si la escogida
 * quedó por debajo de la de 50 semanas, con la diferencia en colones. La
 * decisión sigue siendo de la empresa; lo que no puede pasar es que el
 * perjuicio quede invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->string('vacation_average_basis', 20)->default('practice')->after('vacation_days_per_month')
                ->comment('practice: 2 semanas si es semanal, 6 meses si no | legal_50_weeks: CT art. 157');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn('vacation_average_basis');
        });
    }
};
