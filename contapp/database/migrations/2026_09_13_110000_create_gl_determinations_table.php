<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz de determinación de cuentas (G/L Account Determination de SAP B1).
 * scope_id es polimórfico según scope_level — mismo patrón que ya usa
 * document_type_permissions.subject_type/subject_id, por eso no lleva FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_determinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope_level')->comment('company|warehouse|item_group|item');
            $table->unsignedBigInteger('scope_id')->nullable()
                ->comment('null solo cuando scope_level=company');
            $table->string('category')->comment('inventory|stock_increase|stock_decrease (el resto del diseño llega con su fase)');
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->foreignId('cost_allocation_rule_id')->nullable()->constrained()->nullOnDelete()
                ->comment('obligatorio si la cuenta tiene requires_cost_center');
            $table->timestamps();

            // Nombre explícito: el auto-generado supera el límite de 64
            // caracteres de MySQL para identificadores.
            $table->unique(['company_id', 'scope_level', 'scope_id', 'category'], 'gl_determinations_scope_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_determinations');
    }
};
