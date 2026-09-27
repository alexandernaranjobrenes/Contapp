<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liquidaciones laborales: lo que se le paga a alguien cuando termina la
 * relación de trabajo.
 *
 * ── Por qué es un documento y no un cálculo de pantalla ──────────────────
 *
 * Una liquidación se firma, se archiva y se puede reclamar hasta un año
 * después (prescripción, CT). Tiene que quedar guardada con las bases que
 * se usaron y los días que se pagaron, no recalcularse cada vez que alguien
 * la abra: las tasas cambian, los salarios promedio cambian, y un documento
 * que da un número distinto cada vez no sirve para defenderse.
 *
 * Por eso —igual que la boleta de planilla— cada línea congela su base, sus
 * días y su tasa.
 *
 * ── Las tres bases no son la misma, y esa es la parte crítica ────────────
 *
 * El manual lo dice sin ambigüedad, y es donde más se equivoca una
 * liquidación hecha a mano:
 *
 *   PREAVISO Y CESANTÍA  promedio de TODOS los salarios —ordinarios y
 *                        extraordinarios— de los últimos 6 MESES (art. 30).
 *   VACACIONES           promedio de las últimas 50 SEMANAS (art. 157).
 *   AGUINALDO            suma del 1 de diciembre anterior a la salida,
 *                        dividida entre 12 (Ley 6949).
 *
 * Usar el salario de la ficha en vez del promedio subestima la liquidación
 * de cualquiera que tenga horas extra o comisiones, que es casi todo el
 * mundo.
 *
 * ── Qué paga cargas y qué no ─────────────────────────────────────────────
 *
 * Solo los salarios pendientes y las vacaciones, por tener naturaleza
 * salarial. La cesantía, el preaviso y el aguinaldo están exentos: son
 * indemnizatorios o tienen exención legal expresa. Cobrarle cargas a la
 * cesantía le quitaría al trabajador un 10,83% que la ley no permite
 * rebajar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labor_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();

            $table->date('termination_date');
            $table->string('reason', 40)
                ->comment('la causal decide qué extremos proceden: ver LaborSettlement::REASONS');
            $table->text('reason_detail')->nullable()->comment('los hechos, para el expediente');

            // ── Las bases, congeladas ───────────────────────────────────
            // Se guardan para que la liquidación sea reproducible y para
            // poder enseñarle al trabajador de dónde salió cada número.
            $table->decimal('average_monthly_salary', 18, 2)->default(0)
                ->comment('promedio de los últimos 6 meses: base de preaviso y cesantía');
            $table->decimal('average_daily_salary', 18, 2)->default(0);
            $table->decimal('vacation_daily_salary', 18, 2)->default(0)
                ->comment('promedio de las últimas 50 semanas: base de vacaciones');
            $table->decimal('christmas_bonus_base', 18, 2)->default(0)
                ->comment('salarios del 1 de diciembre a la salida');

            $table->decimal('years_of_service', 8, 4)->default(0);
            // Si las bases salieron del historial de planillas o hubo que
            // suponerlas del salario de la ficha. Una liquidación calculada
            // sin historial paga de menos, y eso no puede quedar implícito.
            $table->boolean('bases_from_history')->default(true);
            $table->unsignedSmallInteger('history_months_found')->default(0);

            // ── Totales ─────────────────────────────────────────────────
            $table->decimal('total_gross', 18, 2)->default(0);
            $table->decimal('total_ccss', 18, 2)->default(0);
            $table->decimal('total_income_tax', 18, 2)->default(0);
            $table->decimal('total_other_deductions', 18, 2)->default(0);
            $table->decimal('total_net', 18, 2)->default(0);

            $table->string('status', 20)->default('draft')
                ->comment('draft|approved|posted|voided');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('document_type_id')->nullable()
                ->constrained('document_types')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('labor_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('labor_settlement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');

            $table->string('kind', 30)
                ->comment('christmas_bonus|vacation|notice|severance|indemnity|pending_salary|deduction');
            $table->string('code', 30);
            $table->string('name');
            $table->string('detail')->nullable()->comment('cómo se llegó al número, para el trabajador');

            // Días y tarifa congelados: es lo que hace verificable la
            // liquidación sin depender de ninguna configuración de hoy.
            $table->decimal('days', 10, 4)->nullable();
            $table->decimal('daily_rate', 18, 2)->nullable();
            $table->decimal('amount', 18, 2);

            // La cesantía, el preaviso y el aguinaldo están exentos; los
            // salarios y las vacaciones no.
            $table->boolean('subject_to_ccss')->default(false);
            $table->boolean('subject_to_income_tax')->default(false);

            $table->foreignId('account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->timestamps();

            $table->index(['labor_settlement_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labor_settlement_lines');
        Schema::dropIfExists('labor_settlements');
    }
};
