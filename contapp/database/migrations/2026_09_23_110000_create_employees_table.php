<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maestro de empleados.
 *
 * ── Los datos que parecen administrativos y no lo son ────────────────────
 *
 * Varios campos de acá NO son informativos: cambian el cálculo o son
 * obligatorios ante una institución.
 *
 * - `journey_type` (diurna, mixta, nocturna) fija la jornada ordinaria
 *   máxima —8, 7 y 6 horas— y por lo tanto a partir de qué hora una hora
 *   es extra. Con el tipo equivocado, las extras se calculan mal.
 * - `salary_type` decide cómo se deriva el valor de la hora y del día, que
 *   es la base de extras, vacaciones y aguinaldo.
 * - `ccss_number` y el tipo de identificación son obligatorios en la
 *   planilla de la Caja; sin ellos el archivo se rechaza.
 * - `cost_center_id` es lo que hace que el gasto de cada trabajador caiga
 *   en el centro que corresponde sin repartirlo a mano.
 *
 * ── La fecha de ingreso manda más de lo que parece ───────────────────────
 *
 * De `hire_date` dependen la antigüedad, el derecho a vacaciones, el monto
 * de cesantía (Código de Trabajo art. 29) y el preaviso (art. 28). Es el
 * dato que más se consulta y el que peor se corrige después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20)->comment('número de empleado dentro de la compañía');

            // ── Identidad ───────────────────────────────────────────────
            // Dos apellidos separados y no un "nombre completo": la planilla
            // de la CCSS y los reportes de Tributación los piden por
            // separado, y partir una cadena para obtenerlos falla con
            // apellidos compuestos ("de la Cruz", "Vargas Mora").
            $table->string('identification_type', 20)->comment('cedula|dimex|pasaporte');
            $table->string('identification_number', 30);
            $table->string('ccss_number', 30)->nullable()->comment('número de asegurado');
            $table->string('first_name');
            $table->string('last_name1');
            $table->string('last_name2')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('nationality')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('photo_path')->nullable();

            // ── Relación laboral ────────────────────────────────────────
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 40)->nullable()
                ->comment('renuncia|despido_con_causa|despido_sin_causa|vencimiento|mutuo_acuerdo|fallecimiento');
            $table->string('position')->nullable()->comment('puesto');
            $table->string('department')->nullable();
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();
            $table->string('contract_type', 30)->default('indefinido')
                ->comment('indefinido|plazo_fijo|obra_determinada|ocasional');
            // Jornada: fija la ordinaria máxima y con ella el umbral de las
            // horas extra (diurna 8, mixta 7, nocturna 6).
            $table->string('journey_type', 20)->default('diurna')
                ->comment('diurna|mixta|nocturna');
            $table->decimal('weekly_hours', 6, 2)->default(48);

            // ── Remuneración ────────────────────────────────────────────
            $table->string('salary_type', 20)->default('mensual')
                ->comment('mensual|quincenal|semanal|diario|hora');
            $table->decimal('base_salary', 18, 2);

            // ── Pago ────────────────────────────────────────────────────
            $table->string('payment_method', 20)->default('transferencia')
                ->comment('transferencia|cheque|efectivo');
            $table->string('bank_name')->nullable();
            // IBAN: es lo que piden los archivos de pago de la banca
            // nacional, y por eso se guarda tal cual y no troceado.
            $table->string('bank_account', 34)->nullable();

            // ── Impuesto al salario ─────────────────────────────────────
            // Los créditos familiares los declara el trabajador y hay que
            // poder probarlos; no se deducen de ningún otro dato.
            $table->boolean('has_spouse_credit')->default(false);
            $table->unsignedSmallInteger('children_credit_count')->default(0);
            // Un trabajador con otra fuente de ingreso puede pedir que no se
            // le aplique la escala desde cero; queda explícito.
            $table->boolean('is_income_tax_exempt')->default(false);

            // ── Cargas sociales ─────────────────────────────────────────
            // Hay figuras que no cotizan por esta planilla (un pensionado
            // que sigue trabajando, por ejemplo). Es excepción y se declara.
            $table->boolean('is_ccss_exempt')->default(false);

            $table->string('status', 20)->default('active')
                ->comment('active|suspended|terminated');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            // La identificación no se repite dentro de una compañía: dos
            // fichas de la misma persona duplicarían su planilla y sus
            // cargas sociales.
            $table->unique(['company_id', 'identification_number'], 'employees_identification_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
