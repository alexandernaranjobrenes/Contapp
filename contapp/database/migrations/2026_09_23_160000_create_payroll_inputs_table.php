<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los movimientos que se le digitan a un trabajador para un período: horas
 * extra, un bono, un rebajo puntual.
 *
 * ── Por qué se guardan y no viajan solo en la petición ───────────────────
 *
 * Las cuarenta horas extra de una quincena las reportó un jefe de área en
 * una boleta de papel y alguien las digitó. Si vivieran únicamente en el
 * formulario, cerrar el navegador antes de calcular las perdería, y
 * recalcular por corregir un solo dato obligaría a digitarlas todas otra
 * vez. Peor: cuando alguien pregunte en noviembre por qué en marzo se le
 * pagaron esas horas, no habría nada que enseñar más que el monto ya
 * calculado.
 *
 * Guardadas, el recálculo es un botón y el dato de origen queda separado
 * del resultado — que es lo que permite auditar la diferencia entre lo que
 * se reportó y lo que se pagó.
 *
 * ── Lo que NO entra acá ──────────────────────────────────────────────────
 *
 * El salario base (viene de la ficha), las cargas sociales y el impuesto
 * (los calcula el motor) y las cuotas de préstamos (salen de las
 * obligaciones del trabajador, con su saldo). Dejar digitar cualquiera de
 * esos permitiría "cuadrar" una planilla a mano y romper la conciliación
 * con la Caja sin que nada lo avisara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_concept_id')->constrained('payroll_concepts')->cascadeOnDelete();

            // Cuál de los dos manda lo decide el concepto: 'hours' usa la
            // cantidad, 'amount' usa el monto.
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('quantity', 12, 4)->nullable();

            $table->string('notes')->nullable()->comment('de dónde salió el dato');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_inputs');
    }
};
