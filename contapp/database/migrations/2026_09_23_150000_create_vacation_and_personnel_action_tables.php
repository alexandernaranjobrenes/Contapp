<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacaciones y acciones de personal: el historial laboral del trabajador.
 *
 * ── Vacaciones: se llevan por MOVIMIENTOS, no por un saldo ───────────────
 *
 * Guardar un solo campo "días disponibles" es la forma más rápida de
 * perder la trazabilidad: cuando el trabajador reclama —y reclama, porque
 * son días de su vida— no hay forma de explicarle el número. Peor: en una
 * liquidación, los días no disfrutados se pagan, y ese pago tiene que
 * poder sustentarse movimiento por movimiento ante el Ministerio de
 * Trabajo.
 *
 * Acá el saldo es la SUMA de los movimientos: se acredita por mes
 * trabajado, se rebaja al disfrutar, se rebaja al pagar en efectivo, y se
 * ajusta a mano solo dejando rastro de quién y por qué.
 *
 * ── Acciones de personal: qué cambió, desde cuándo, y quién lo autorizó ──
 *
 * Un aumento de salario no es una edición de la ficha. Es un hecho con
 * fecha de vigencia, un antes y un después, un motivo y un responsable.
 * Si solo se edita el campo `base_salary`, la planilla del mes pasado
 * queda sin explicación y nadie puede decir desde cuándo rige el aumento
 * ni quién lo aprobó.
 *
 * Por eso la acción guarda el valor anterior y el nuevo: la ficha refleja
 * el presente, y estas filas explican cómo se llegó a él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20)->comment('accrual|taken|paid|adjustment');
            $table->date('movement_date');
            // Positivo acredita, negativo rebaja. Un solo signo evita tener
            // que recordar en cada consulta cuáles tipos suman y cuáles
            // restan — el saldo es literalmente SUM(days).
            //
            // Cuatro decimales y no dos: una quincena acredita una fracción
            // de día, y redondearla a céntimos de día pierde algo en cada
            // período. Dos décimas de error por quincena son casi cinco días
            // en diez años, y son días que el trabajador se gana.
            $table->decimal('days', 10, 4);

            // Rango disfrutado, cuando aplica.
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            // De qué período salió la acreditación, o en cuál se pagó.
            $table->foreignId('payroll_period_id')->nullable()
                ->constrained('payroll_periods')->nullOnDelete();
            $table->foreignId('payroll_entry_id')->nullable()
                ->constrained('payroll_entries')->nullOnDelete();

            $table->decimal('amount', 18, 2)->nullable()->comment('monto pagado, si se pagaron');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'movement_date']);
            // Una acreditación por empleado y período: es lo que impide que
            // correr dos veces la acreditación automática duplique los días.
            $table->unique(['employee_id', 'payroll_period_id', 'type'], 'vacation_accrual_unique');
        });

        Schema::create('personnel_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('action_type', 40)
                ->comment('hire|salary_change|position_change|cost_center_change|journey_change|suspension|reinstatement|termination');
            // Desde cuándo rige. NO es la fecha en que se digitó: un aumento
            // acordado en marzo puede regir desde enero, y la planilla tiene
            // que poder saberlo.
            $table->date('effective_date');

            // El antes y el después, en texto: sirve para salario, puesto,
            // centro de costo o jornada sin una columna por cada uno.
            $table->string('previous_value')->nullable();
            $table->string('new_value')->nullable();
            $table->string('field', 40)->nullable()->comment('campo de la ficha que cambia');

            $table->text('reason')->nullable();
            $table->string('status', 20)->default('draft')
                ->comment('draft|approved|applied|cancelled');

            // Quién la pidió y quién la aprobó no pueden ser el mismo campo:
            // un aumento que se aprueba solo no es una aprobación.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'effective_date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_actions');
        Schema::dropIfExists('vacation_movements');
    }
};
