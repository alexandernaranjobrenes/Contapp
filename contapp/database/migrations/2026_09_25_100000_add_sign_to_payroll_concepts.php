<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El signo del rubro: hay ingresos que RESTAN del salario devengado.
 *
 * ── El caso que no se podía representar ──────────────────────────────────
 *
 * Las horas de incapacidad. El patrono no las paga —las cubre el subsidio de
 * la CCSS o del INS— así que hay que quitarlas del devengado.
 *
 * Modelarlas como una deducción NO sirve, y la diferencia es plata: una
 * deducción rebaja el neto pero deja intacta la base de cargas sociales, y
 * sobre horas que no se pagaron no se cotiza. Con una deducción, la empresa
 * y el trabajador cotizarían sobre un salario que nadie devengó.
 *
 * ── Por qué el signo va en el RUBRO y no en el dato ──────────────────────
 *
 * Porque «horas de incapacidad» siempre resta; no es que a veces reste. El
 * usuario digita 8 horas en positivo y el rubro sabe qué hacer con ellas.
 * Permitir montos negativos en la captura abriría la puerta a que cualquier
 * rubro reste por un error de tecleo, y un menos de más en una bonificación
 * no lo detecta nadie hasta que el trabajador reclama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_concepts', function (Blueprint $table) {
            // -1 resta del devengado, 1 suma. Solo tiene sentido en rubros de
            // ingreso: una deducción ya resta por su naturaleza.
            $table->tinyInteger('sign')->default(1)->after('type')
                ->comment('1 suma al devengado, -1 lo resta (incapacidad, ausencias)');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_concepts', function (Blueprint $table) {
            $table->dropColumn('sign');
        });
    }
};
