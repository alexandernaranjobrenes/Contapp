<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Obligaciones recurrentes que se le rebajan al trabajador en cada planilla:
 * adelantos de salario, préstamos, ahorro y cuota solidarista, pensión
 * alimentaria, embargos.
 *
 * ── Por qué UNA tabla y no una por figura ────────────────────────────────
 *
 * Un adelanto de salario, un préstamo de la asociación solidarista y un
 * embargo judicial se ven como tres cosas distintas y se comportan igual:
 * un monto que se rebaja cada período, contra una cuenta, hasta que se
 * acabe o para siempre. Modelarlas por separado obligaría a triplicar el
 * cálculo, el tope legal y la contabilización, y las tres copias se
 * separarían a la primera corrección.
 *
 * Lo que de verdad las diferencia son dos ejes, y los dos son columnas:
 *
 *   - ¿tiene saldo? un préstamo sí y se extingue; un ahorro no y sigue
 *     indefinidamente (balance null).
 *   - ¿monto fijo o porcentaje? la cuota solidarista es un porcentaje del
 *     salario; una cuota de préstamo es un monto.
 *
 * ── La prioridad no es decorativa ────────────────────────────────────────
 *
 * Cuando el salario no alcanza para todos los rebajos hay que decidir
 * cuáles se aplican. Esa decisión no puede depender del orden en que se
 * digitaron: una pensión alimentaria tiene preferencia sobre un préstamo
 * de consumo. `priority` la hace explícita y auditable.
 *
 * ── El tope de deducciones ───────────────────────────────────────────────
 *
 * Una planilla nunca debe producir un neto negativo ni consumir el salario
 * entero en rebajos. El motor aplica las obligaciones en orden de prioridad
 * y se detiene cuando el siguiente rebajo rompería el tope configurado,
 * dejando el resto pendiente para el período siguiente en vez de "cuadrar"
 * la planilla a costa del trabajador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('type', 30)
                ->comment('advance|loan|solidarista_savings|solidarista_fee|alimony|garnishment|other');
            $table->string('reference', 60)->nullable()->comment('n.º de préstamo, expediente judicial');
            $table->string('description');

            // El concepto de deducción con el que entra a la boleta. Es lo
            // que le da nombre en el comprobante y cuenta contable.
            $table->foreignId('payroll_concept_id')->nullable()
                ->constrained('payroll_concepts')->nullOnDelete();

            $table->date('start_date');
            // Hasta cuándo se aplica. Nullable = indefinida (ahorro, cuota).
            $table->date('end_date')->nullable();

            $table->decimal('original_amount', 18, 2)->nullable()
                ->comment('monto otorgado; null si no tiene saldo que extinguir');
            // El saldo vivo. Null = obligación sin saldo (ahorro, cuota,
            // pensión): se rebaja indefinidamente y nunca se extingue sola.
            $table->decimal('balance', 18, 2)->nullable();

            // Una de las dos manda, según `calculation`.
            $table->string('calculation', 20)->default('amount')->comment('amount|percentage');
            $table->decimal('installment_amount', 18, 2)->nullable();
            $table->decimal('installment_percentage', 8, 4)->nullable()
                ->comment('% del salario bruto del período');

            // Menor número = se aplica primero cuando el salario no alcanza.
            $table->unsignedSmallInteger('priority')->default(100);

            // La contrapartida contable del rebajo: la cuenta por cobrar del
            // adelanto, el pasivo con la asociación solidarista, etc. Si
            // está vacía se usa la del concepto.
            $table->foreignId('account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            $table->string('status', 20)->default('active')
                ->comment('active|suspended|settled|cancelled');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'status']);
            $table->index(['company_id', 'type']);
        });

        // Cada rebajo aplicado, contra su obligación. Es el estado de cuenta
        // del préstamo: sin él, `balance` sería un número sin historia y
        // nadie podría explicarle al trabajador de dónde salió.
        Schema::create('employee_deduction_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_deduction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_entry_id')->nullable()
                ->constrained('payroll_entries')->cascadeOnDelete();
            $table->date('applied_on');
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->timestamps();

            $table->index('employee_deduction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_deduction_applications');
        Schema::dropIfExists('employee_deductions');
    }
};
