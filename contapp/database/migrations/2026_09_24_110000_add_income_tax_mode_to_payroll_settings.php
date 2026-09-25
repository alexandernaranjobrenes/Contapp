<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se calcula el impuesto al salario: sobre qué base y con qué método.
 *
 * ── Por qué el MÉTODO es un parámetro ────────────────────────────────────
 *
 * La escala del impuesto está escrita en montos MENSUALES. Con planilla
 * quincenal hay que llegar al mes de alguna manera, y hay dos:
 *
 *   PROYECTADO   se toma la base de la quincena, se multiplica por dos, se
 *                aplica la escala y se retiene la mitad.
 *
 *   ACUMULADO    la primera quincena aporta su base; en la segunda se SUMA
 *                lo devengado de las dos, se aplica la escala al total del
 *                mes y se retiene la diferencia contra lo ya retenido.
 *
 * El acumulado es mejor y por eso es el valor por defecto: usa lo que de
 * verdad se devengó en vez de suponer que la segunda quincena será igual a
 * la primera. Con comisiones concentradas en una quincena —que es lo
 * normal— el proyectado retiene de más en una y de menos en la otra, y solo
 * cuadra por casualidad.
 *
 * El acumulado además se autocorrige: si la primera quincena ya superaba el
 * tramo exento, retiene ahí, y la segunda solo retiene el incremento. No
 * hace falta que nadie marque nada.
 *
 * El proyectado queda disponible porque hay empresas que lo usan y cambiarles
 * el método a mitad de año les movería las retenciones.
 *
 * ── Por qué la BASE es un parámetro ──────────────────────────────────────
 *
 * Si la escala se aplica sobre el salario devengado, o sobre el devengado
 * menos las cargas sociales obreras, es una cuestión de la Ley del Impuesto
 * sobre la Renta y no de este sistema. El valor por defecto —devengado— es
 * el criterio del usuario contador; dejarlo fijo en código sería afirmar una
 * interpretación fiscal sin poder sustentarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->string('income_tax_mode', 20)->default('accumulated')->after('max_deduction_percentage')
                ->comment('accumulated|projected');

            $table->string('income_tax_base', 30)->default('gross')->after('income_tax_mode')
                ->comment('gross|net_of_contributions');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn(['income_tax_mode', 'income_tax_base']);
        });
    }
};
