<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El trabajador pensionado: cotiza SEM pero NO IVM.
 *
 * ── Por qué no alcanzaba `is_ccss_exempt` ────────────────────────────────
 *
 * Esa bandera saca al trabajador de TODAS las cargas. Un pensionado que
 * sigue trabajando no está exento: sigue cotizando Enfermedad y Maternidad
 * y el Banco Popular. Lo que no cotiza —ni él ni el patrono— es Invalidez,
 * Vejez y Muerte, porque ya está pensionado por ese régimen.
 *
 *   Obrero normal      5,50 SEM + 4,33 IVM + 1,00 BP  =  10,83%
 *   Obrero pensionado  5,50 SEM +          + 1,00 BP  =   6,50%
 *
 * Usar `is_ccss_exempt` con un pensionado le quitaría también el 6,5% que
 * sí debe, y la empresa quedaría debiéndole esa diferencia a la Caja.
 *
 * ── Por qué la exención va marcada en la CARGA y no escrita en el motor ──
 *
 * El motor podría preguntar «¿el código empieza por IVM?». Sería frágil:
 * el día que la Caja renombre un componente o una empresa cree el suyo con
 * otro código, el pensionado empezaría a cotizar IVM sin que nada lo avise.
 *
 * Con la bandera en la fila, la regla queda donde se puede ver y auditar:
 * cada carga declara si un pensionado la paga. Y si mañana apareciera otro
 * componente del que los pensionados están exentos, se marca y listo, sin
 * tocar código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_pensioner')->default(false)->after('is_ccss_exempt')
                ->comment('pensionado: cotiza SEM pero no IVM, ni obrero ni patronal');
        });

        Schema::table('payroll_contributions', function (Blueprint $table) {
            $table->boolean('exempt_for_pensioner')->default(false)->after('base')
                ->comment('los componentes de IVM: un pensionado ya no cotiza por ese régimen');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_contributions', function (Blueprint $table) {
            $table->dropColumn('exempt_for_pensioner');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('is_pensioner');
        });
    }
};
