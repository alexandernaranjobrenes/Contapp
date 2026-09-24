<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La planilla en sí: el período, la boleta de cada empleado y el detalle
 * línea por línea.
 *
 * ── Por qué el detalle se GUARDA y no se recalcula ───────────────────────
 *
 * Sería más barato guardar solo los totales y recalcular el desglose cuando
 * alguien lo pida. Sería también un error: una planilla es un hecho
 * histórico. Si mañana cambia una tasa, sube el salario o se corrige la
 * fecha de ingreso de alguien, el desglose de la planilla de marzo tiene
 * que seguir diciendo lo que dijo en marzo — es lo que el trabajador
 * recibió, lo que se le reportó a la Caja y lo que se contabilizó.
 *
 * Por eso cada línea guarda su monto calculado, y las de carga social e
 * impuesto guardan además la TASA que se les aplicó. Una planilla se puede
 * reproducir sin depender de ninguna configuración actual.
 *
 * ── El estado es una máquina, no una etiqueta ────────────────────────────
 *
 *   abierta → calculada → aprobada → contabilizada → cerrada
 *
 * Recalcular solo se puede mientras está abierta o calculada. Una vez
 * contabilizada existe un asiento que la respalda, y modificarla por
 * detrás dejaría la contabilidad diciendo una cosa y la planilla otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('frequency', 20)->comment('quincenal|mensual|semanal');
            $table->unsignedSmallInteger('number')->comment('número del período dentro del año');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            // La fecha de pago manda para el asiento y para el archivo de
            // pago; puede caer en un mes distinto al del período trabajado.
            $table->date('payment_date');
            $table->string('status', 20)->default('open')
                ->comment('open|calculated|approved|posted|closed');

            // El asiento que la respalda. Nullable hasta que se contabiliza.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('document_type_id')->nullable()
                ->constrained('document_types')->nullOnDelete();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'frequency', 'number'], 'payroll_period_unique');
            $table->index(['company_id', 'status']);
        });

        // La boleta: una por empleado por período. Es el comprobante de pago.
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            // Se congela el centro de costo del momento: si el trabajador se
            // traslada después, la planilla vieja tiene que seguir cargando
            // al centro que de verdad soportó ese gasto.
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();

            // Días y horas efectivamente considerados. Una incapacidad o un
            // ingreso a mitad de quincena los baja, y de ellos dependen el
            // salario devengado y las provisiones.
            $table->decimal('days_worked', 8, 2);
            $table->decimal('base_salary', 18, 2)->comment('el vigente al calcular, congelado');

            // Los totales del cálculo, en el orden en que se construyen.
            $table->decimal('total_earnings', 18, 2)->default(0);
            $table->decimal('ccss_base', 18, 2)->default(0)
                ->comment('parte del bruto que sí forma salario para cargas');
            $table->decimal('income_tax_base', 18, 2)->default(0);
            $table->decimal('total_employee_contributions', 18, 2)->default(0);
            $table->decimal('income_tax', 18, 2)->default(0);
            $table->decimal('total_other_deductions', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2)->default(0);
            $table->decimal('net_pay', 18, 2)->default(0);

            // Lo que paga el patrono ADEMÁS del salario. No rebaja nada al
            // trabajador, pero es costo de la empresa y por eso va en la
            // boleta: sin esto nadie sabe cuánto cuesta de verdad la planilla.
            $table->decimal('total_employer_contributions', 18, 2)->default(0);
            $table->decimal('total_provisions', 18, 2)->default(0);

            $table->string('payment_method', 20)->nullable();
            $table->string('bank_account', 34)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id'], 'payroll_entry_unique');
            $table->index('employee_id');
        });

        Schema::create('payroll_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');

            // De dónde salió la línea. Se guarda el tipo y no solo el id
            // porque las cuatro familias se presentan y se contabilizan
            // distinto, y una boleta las tiene que separar a la vista.
            $table->string('kind', 30)
                ->comment('earning|employee_contribution|income_tax|deduction|employer_contribution|provision');
            $table->string('code', 30);
            $table->string('name');

            // El origen concreto, para poder rastrear la línea hasta su
            // configuración. Nullable porque el impuesto no tiene concepto.
            $table->foreignId('payroll_concept_id')->nullable()
                ->constrained('payroll_concepts')->nullOnDelete();
            $table->foreignId('payroll_contribution_id')->nullable()
                ->constrained('payroll_contributions')->nullOnDelete();
            $table->foreignId('payroll_provision_id')->nullable()
                ->constrained('payroll_provisions')->nullOnDelete();

            // La base y la tasa CONGELADAS. Es lo que permite reproducir la
            // planilla dentro de cinco años sin depender de la configuración
            // de entonces, y lo que hace auditable cada rebajo.
            $table->decimal('base_amount', 18, 2)->nullable();
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('quantity', 12, 4)->nullable()->comment('horas o días, si aplica');
            $table->decimal('amount', 18, 2);

            $table->foreignId('account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_entry_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entry_lines');
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_periods');
    }
};
