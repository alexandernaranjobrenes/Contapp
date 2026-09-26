<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bitácora del período: quién lo calculó, quién lo aprobó, quién lo
 * reabrió y quién lo anuló, con la razón.
 *
 * ── Por qué una tabla y no unas columnas en el período ───────────────────
 *
 * Porque un período se puede reabrir más de una vez, y unas columnas
 * `reopened_at` / `reopened_by` solo guardarían la última. La segunda vez
 * que alguien reabre una planilla, la primera desaparecería — y es
 * exactamente el caso en que a alguien le va a interesar el historial.
 *
 * ── Qué hace distinta a la anulación ─────────────────────────────────────
 *
 * Reabrir una planilla aprobada no sacó nada del sistema: se vuelve atrás y
 * ya. Anular una CONTABILIZADA sí: hay un asiento, se movieron saldos de
 * préstamo y se acreditaron vacaciones. Por eso la anulación exige razón y
 * queda ligada al asiento de reversión que la respalda.
 *
 * Nada se borra: es la misma regla con la que CONTAPP trata cualquier
 * documento contabilizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_period_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();

            $table->string('event', 30)
                ->comment('calculated|approved|posted|reopened|voided|closed');

            // De dónde a dónde: el estado por sí solo no dice si fue un
            // avance o una vuelta atrás.
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            // Obligatoria al reabrir y al anular. Un cambio de estado sin
            // motivo es un dato que no sirve para nada seis meses después.
            $table->text('reason')->nullable();

            // El asiento de reversión, cuando el evento es una anulación.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_period_id', 'created_at']);
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            // El asiento que revierte al de la planilla. Se guarda en el
            // período —y no solo en la bitácora— porque la pantalla lo tiene
            // que enseñar al lado del asiento original.
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
        });

        Schema::dropIfExists('payroll_period_events');
    }
};
