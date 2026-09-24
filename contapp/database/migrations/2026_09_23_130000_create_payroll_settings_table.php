<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cuentas contables de la planilla, y la cuenta de gasto por empleado.
 *
 * ── Por qué una tabla de configuración y no cuentas en código ────────────
 *
 * Cada compañía tiene su propio plan de cuentas. La cuenta donde cae el
 * salario en una empresa de servicios no es la misma que en una planta, y
 * ni siquiera tienen el mismo código. Lo único fijo es el ROL que cada
 * cuenta juega en el asiento; el número lo pone la compañía.
 *
 * ── Por qué el empleado puede sobreescribir la cuenta de gasto ───────────
 *
 * El centro de costo dice DÓNDE se consumió el trabajo; la cuenta dice QUÉ
 * es ese gasto. Son dos preguntas distintas y en una empresa que fabrica
 * tienen respuestas distintas: la mano de obra directa de planta es costo
 * de producción, y el salario del contador es gasto administrativo, aunque
 * ambos salgan de la misma planilla. Por eso la cuenta se resuelve
 * empleado → configuración de la compañía, igual que la determinación de
 * cuentas de inventario resuelve artículo → grupo → almacén → compañía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            // Una sola fila por compañía: es configuración, no un catálogo.
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            // El gasto por defecto del salario, cuando el empleado no trae
            // una cuenta propia.
            $table->foreignId('salary_expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            // El pasivo con el trabajador: el neto que se le debe entre que
            // se contabiliza la planilla y que se le paga. Aunque se pague
            // el mismo día, el asiento de planilla y el de pago son dos
            // hechos distintos y esta cuenta es la que los une.
            $table->foreignId('net_payable_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            // La retención del impuesto al salario, que es del trabajador y
            // la empresa solo la custodia hasta enterarla a Tributación.
            $table->foreignId('income_tax_payable_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            // El tipo de documento con el que se contabiliza la planilla.
            $table->foreignId('document_type_id')->nullable()
                ->constrained('document_types')->nullOnDelete();

            // Vacaciones: días que se acreditan por mes trabajado. El Código
            // de Trabajo fija un mínimo, pero una empresa puede conceder más
            // y muchas lo hacen por convenio; por eso es parámetro.
            $table->decimal('vacation_days_per_month', 6, 4)->default(1);

            // Tope de deducciones como porcentaje del neto. Existe para que
            // la suma de préstamos y rebajos no deje al trabajador sin
            // salario; 0 significa sin tope.
            $table->decimal('max_deduction_percentage', 5, 2)->default(0);

            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            // Sobreescribe la cuenta de gasto de la compañía. Nullable a
            // propósito: lo normal es dejarla vacía y heredar.
            $table->foreignId('salary_expense_account_id')->nullable()->after('cost_center_id')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_expense_account_id');
        });

        Schema::dropIfExists('payroll_settings');
    }
};
