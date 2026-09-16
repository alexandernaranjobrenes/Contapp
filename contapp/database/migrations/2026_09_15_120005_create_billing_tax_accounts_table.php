<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puente entre la tarifa de IVA de la norma (Nota 8.1: 01, 08, 10...) y las
 * dos cosas que el ERP necesita de ella: contra qué cuenta se acredita el
 * débito fiscal, y a qué `tax_rate` del proyecto corresponde.
 *
 * Vive en el módulo de facturación y NO como columna de `tax_rates` para no
 * tocar la configuración fiscal existente. El `tax_rate_id` es lo que permite
 * que el reporte de IVA que ya existe siga viendo estas ventas sin cambios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_tax_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('iva_rate_code', 2)->comment('Nota 8.1');
            $table->foreignId('account_id')->constrained('chart_of_accounts')
                ->comment('IVA débito fiscal por pagar de esa tarifa');
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete()
                ->comment('indicador de impuesto equivalente del proyecto, para el reporte de IVA');
            $table->timestamps();

            $table->unique(['company_id', 'iva_rate_code'], 'billing_tax_accounts_rate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_tax_accounts');
    }
};
