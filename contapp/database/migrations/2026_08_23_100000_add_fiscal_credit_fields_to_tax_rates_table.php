<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Indicadores de impuesto" (antes "Tarifas de IVA" — mismo catálogo, solo
 * cambia el nombre de cara al usuario) ganan si dan o no derecho a crédito
 * fiscal — en Costa Rica no todos los indicadores de IVA lo dan por igual
 * (ej. tarifa reducida vs. general, o ninguno salvo exportaciones/
 * exoneraciones). grants_fiscal_credit es el sí/no; fiscal_credit_note es
 * texto libre para la matización (ej. "Crédito pleno", "Tarifa reducida",
 * "Salvo exportaciones/exoneraciones") — no se fuerza un catálogo cerrado de
 * categorías porque la nomenclatura exacta de Hacienda puede variar y no
 * conviene codificarla como si fuera una regla de negocio propia del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->boolean('grants_fiscal_credit')->default(false)->after('percentage');
            $table->string('fiscal_credit_note')->nullable()->after('grants_fiscal_credit');
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropColumn(['grants_fiscal_credit', 'fiscal_credit_note']);
        });
    }
};
