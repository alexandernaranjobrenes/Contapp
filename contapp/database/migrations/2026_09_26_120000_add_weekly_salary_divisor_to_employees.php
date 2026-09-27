<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El divisor del salario diario en el pago semanal: 6 o 7.
 *
 * ── Por qué no es uno solo ───────────────────────────────────────────────
 *
 * Depende de si el salario semanal ya incluye el día de descanso:
 *
 *   SEIS   sector productivo o agrícola, donde la semana paga únicamente los
 *          6 días efectivamente laborados. Dividir entre 7 diluiría la cuota
 *          diaria del trabajador y le pagaría de menos cada día de
 *          liquidación, vacaciones o incapacidad.
 *
 *   SIETE  comercio, donde el patrono liquida los 7 días y remunera de forma
 *          expresa el descanso semanal (CT art. 152). Dividir entre 6 le
 *          pagaría de más.
 *
 * Entre una y otra hay un 16% de diferencia en el valor del día. No es un
 * detalle que se pueda dejar implícito: es el número que multiplica los días
 * de cesantía, preaviso y vacaciones en una liquidación.
 *
 * El valor por defecto es 6 —el criterio que indicó el usuario— y solo tiene
 * efecto en los trabajadores con salario semanal; para los demás el divisor
 * lo fija su propia modalidad (30 al mes, 15 la quincena).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('weekly_salary_divisor')->default(6)->after('weekly_hours')
                ->comment('solo para salario semanal: 6 sin descanso pagado, 7 con descanso pagado');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('weekly_salary_divisor');
        });
    }
};
