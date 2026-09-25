<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rubros FIJOS por empleado: los que se repiten cada período sin digitarse.
 *
 * ── El caso que resuelve ─────────────────────────────────────────────────
 *
 * Una bonificación de conectividad de ₡25.000 al mes, un salario en especie,
 * una asignación de vehículo, un rebajo acordado por uso de teléfono. No
 * cambian de un período al siguiente, y hoy había que volver a digitarlos
 * veinticuatro veces al año, por persona. Digitar un dato que no cambia es
 * exactamente donde aparecen los errores de dedo.
 *
 * ── Por qué la recurrencia va acá y no en el concepto ────────────────────
 *
 * `payroll_concepts.is_recurring` existía y el motor nunca lo leía. Y estaba
 * en el lugar equivocado: la recurrencia no es una propiedad del rubro sino
 * de la ASIGNACIÓN del rubro a una persona. «Bonificación» no es recurrente
 * ni no recurrente; es fija para Ana y ocasional para Luis.
 *
 * En el concepto, `is_recurring` queda con otro significado, más honesto:
 * «este rubro se puede asignar como fijo», que es lo que filtra la pantalla.
 *
 * ── Por qué no se reusó employee_deductions ─────────────────────────────
 *
 * Porque esa tabla es de obligaciones: lleva saldo, prioridad y se extingue.
 * Un ingreso fijo no tiene saldo ni compite por el neto disponible. Meterlo
 * ahí habría obligado a que la mitad de sus columnas fueran nulas y a que el
 * motor preguntara «¿es esto un ingreso?» en cada paso del rebajo.
 *
 * ── La vigencia, otra vez ───────────────────────────────────────────────
 *
 * Con fechas, un aumento de la bonificación se carga hoy con vigencia del mes
 * entrante y entra solo. Sin ellas habría que acordarse de cambiarlo el día
 * exacto, que es como se paga de menos un mes y de más el siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_recurring_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_concept_id')->constrained('payroll_concepts')->cascadeOnDelete();

            // Cuál de los dos manda lo decide el concepto, igual que en
            // payroll_inputs: 'hours' usa la cantidad, el resto el monto.
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('quantity', 12, 4)->nullable();

            $table->date('start_date');
            $table->date('end_date')->nullable()->comment('nullable = indefinido');

            $table->string('notes')->nullable();
            $table->string('status', 20)->default('active')->comment('active|suspended');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un mismo rubro fijo dos veces para la misma persona en la misma
            // vigencia sería un pago doble silencioso.
            $table->unique(['employee_id', 'payroll_concept_id', 'start_date'], 'employee_recurring_unique');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_recurring_inputs');
    }
};
