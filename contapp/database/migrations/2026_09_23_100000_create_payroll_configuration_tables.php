<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de planilla: cargas sociales, tramos del impuesto al salario
 * y conceptos de ingreso y deducción.
 *
 * ── Por qué NADA de esto vive en código ──────────────────────────────────
 *
 * Los porcentajes de la CCSS, del INS y los tramos del impuesto al salario
 * los fija un decreto y cambian. Un porcentaje escrito en una clase PHP
 * obliga a un despliegue cada vez que la Caja mueve una décima, y —peor—
 * hace que una planilla de hace ocho meses se recalcule con la tasa de hoy,
 * que es exactamente lo que un ajuste o una fiscalización no perdona.
 *
 * Por eso toda tasa lleva VIGENCIA: el cálculo de una planilla de marzo usa
 * las tasas que regían en marzo, no las de hoy. Es la misma regla que
 * CLAUDE.md secc. 7 ya fija para el IVA, aplicada a cargas sociales.
 *
 * ── Por qué por compañía y no nacional ───────────────────────────────────
 *
 * La mayoría de los porcentajes son nacionales, pero NO todos: la póliza de
 * Riesgos del Trabajo del INS depende de la actividad económica de cada
 * patrono y va de una fracción de punto a varios puntos. Con una tabla
 * nacional habría que inventar una excepción justo para el componente que
 * más varía. Cada compañía tiene su juego, y el seeder lo precarga.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Componentes de carga social ─────────────────────────────────
        //
        // Una fila por componente (SEM obrero, IVM patronal, INA, FCL, ROP,
        // Riesgos del Trabajo...) y no una sola tasa agregada, porque cada
        // uno tiene su propia cuenta contable, su propio acreedor y su
        // propia vigencia. Agregarlos en "10,67% obrero" haría imposible
        // conciliar contra la planilla de la CCSS, que los desglosa.
        Schema::create('payroll_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            // employee = se le rebaja al trabajador de su salario bruto.
            // employer = lo paga el patrono ADEMÁS del salario; es costo de
            // la empresa y no rebaja nada al trabajador.
            $table->string('payer', 20)->comment('employee|employer');
            // Agrupa para el reporte y para la planilla de la Caja.
            $table->string('institution', 30)->comment('ccss|ins|ina|imas|banco_popular|fcl|rop|otro');
            $table->decimal('percentage', 8, 4);
            // Base sobre la que se aplica. Casi siempre el salario bruto
            // sujeto a cargas, pero se deja explícito porque hay
            // componentes que se calculan sobre bases distintas.
            $table->string('base', 30)->default('ccss_base')->comment('ccss_base|gross');
            // Tope salarial del componente, si lo tuviera. Nullable = sin
            // tope, que es el caso de la mayoría.
            $table->decimal('ceiling_amount', 18, 2)->nullable();

            // El asiento: el gasto (solo patronales) y el pasivo que queda
            // por pagar a la institución.
            $table->foreignId('expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('liability_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('legal_basis')->nullable()
                ->comment('norma que lo sustenta, para poder auditarlo');
            $table->timestamps();

            // Un mismo componente puede existir varias veces con vigencias
            // distintas —es justamente cómo se registra un cambio de tasa—
            // así que la unicidad incluye la fecha de inicio.
            $table->unique(['company_id', 'code', 'valid_from'], 'payroll_contrib_unique');
            $table->index(['company_id', 'payer', 'status']);
        });

        // ── Tramos del impuesto al salario ──────────────────────────────
        //
        // Escala mensual progresiva. Se guarda tramo por tramo y no como
        // fórmula porque los montos los fija un decreto anual y la forma de
        // la escala puede cambiar (tramos nuevos, tasas distintas).
        Schema::create('payroll_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedSmallInteger('bracket_number');
            $table->decimal('from_amount', 18, 2);
            // Null en el último tramo: de ahí en adelante, sin techo.
            $table->decimal('to_amount', 18, 2)->nullable();
            $table->decimal('percentage', 8, 4);
            $table->timestamps();

            $table->unique(['company_id', 'valid_from', 'bracket_number'], 'payroll_bracket_unique');
        });

        // ── Créditos familiares ─────────────────────────────────────────
        //
        // Montos fijos mensuales que se restan del impuesto calculado, no de
        // la base. Restarlos de la base daría un resultado distinto y menor.
        Schema::create('payroll_tax_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20)->comment('spouse|child');
            $table->string('name');
            $table->decimal('monthly_amount', 18, 2);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code', 'valid_from'], 'payroll_credit_unique');
        });

        // ── Conceptos de planilla ───────────────────────────────────────
        //
        // Todo lo que no es carga social ni impuesto: horas extra, bonos,
        // comisiones, viáticos, adelantos, embargos, cuota solidarista,
        // préstamos.
        //
        // Los tres indicadores de abajo son EL corazón de la planilla
        // costarricense, y equivocarlos es el error que produce diferencias
        // con la Caja y con Tributación:
        //
        //   affects_ccss       ¿forma salario para cargas sociales?
        //                      Las horas extra sí; los viáticos liquidados
        //                      contra factura no.
        //   affects_income_tax ¿entra a la base del impuesto al salario?
        //                      El aguinaldo está exento hasta el límite de
        //                      ley; una bonificación ordinaria no.
        //   affects_provisions ¿cuenta para aguinaldo, vacaciones y
        //                      cesantía? Lo que es salario ordinario sí.
        //
        // Se declaran por concepto y no se deducen del tipo, porque dos
        // conceptos del mismo tipo pueden diferir en los tres.
        Schema::create('payroll_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('type', 20)->comment('earning|deduction');
            $table->boolean('affects_ccss')->default(true);
            $table->boolean('affects_income_tax')->default(true);
            $table->boolean('affects_provisions')->default(true);

            // Cómo se obtiene el monto.
            //   amount       se digita por empleado y período
            //   percentage   porcentaje del salario base
            //   hours        cantidad × valor hora × factor (horas extra)
            $table->string('calculation', 20)->default('amount');
            $table->decimal('factor', 8, 4)->nullable()
                ->comment('porcentaje, o multiplicador de la hora ordinaria');

            $table->foreignId('account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('is_recurring')->default(false)
                ->comment('se arrastra a cada planilla hasta que se desactive');
            $table->string('status', 20)->default('active');
            $table->string('legal_basis')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type', 'status']);
        });

        // ── Parámetros de provisión ─────────────────────────────────────
        //
        // Aguinaldo, vacaciones, cesantía y preaviso se ACUMULAN mes a mes
        // aunque se paguen después: son obligaciones que nacen con el
        // trabajo del período, no con su pago. Registrarlas solo al pagarlas
        // dejaría los estados financieros sin un pasivo que ya existe y
        // cargaría a diciembre un gasto que se devengó todo el año.
        //
        // El porcentaje va acá y no en código porque la cesantía depende de
        // la antigüedad (Código de Trabajo art. 29) y cada empresa puede
        // provisionar con criterios distintos.
        Schema::create('payroll_provisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->comment('aguinaldo|vacaciones|cesantia|preaviso');
            $table->string('name');
            $table->decimal('percentage', 8, 4)
                ->comment('sobre el salario devengado del período');
            $table->foreignId('expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('liability_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('legal_basis')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code', 'valid_from'], 'payroll_provision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_provisions');
        Schema::dropIfExists('payroll_concepts');
        Schema::dropIfExists('payroll_tax_credits');
        Schema::dropIfExists('payroll_tax_brackets');
        Schema::dropIfExists('payroll_contributions');
    }
};
