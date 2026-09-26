<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora del funcionario: hechos, anotaciones y observaciones con fecha.
 *
 * ── Por qué no alcanza el campo `notas` de la ficha ──────────────────────
 *
 * Porque es uno solo y se sobrescribe. Quien anota «llegó tarde tres veces
 * en marzo» borra lo que decía antes, y seis meses después no queda ni el
 * hecho ni quién lo anotó. Un seguimiento que se pisa a sí mismo no es un
 * seguimiento.
 *
 * ── Por qué separada de las acciones de personal ─────────────────────────
 *
 * Una acción de personal CAMBIA la ficha —el salario, el puesto, el estado—
 * y por eso tiene vigencia, aprobación y un valor anterior y uno nuevo. Una
 * anotación no cambia nada: es un hecho que alguien registró. Mezclarlas
 * obligaría a que cada observación pasara por un flujo de aprobación, y
 * nadie anotaría nada.
 *
 * Lo que sí comparten es que ninguna se borra: se corrigen anotando encima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // La fecha del HECHO, que no es la de la anotación: se puede
            // registrar hoy algo que pasó la semana pasada.
            $table->date('happened_on');

            $table->string('category', 30)->default('observation')
                ->comment('observation|recognition|warning|incident|meeting|other');

            $table->string('title');
            $table->text('body');

            // Una anotación sensible no debería verse en cualquier pantalla
            // que liste al trabajador.
            $table->boolean('is_confidential')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'happened_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_notes');
    }
};
