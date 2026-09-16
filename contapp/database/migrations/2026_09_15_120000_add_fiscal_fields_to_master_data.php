<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos que el XML v4.4 exige y que los maestros todavía no tenían. Todo es
 * aditivo y nullable: ningún flujo existente de contabilidad o inventario
 * cambia, y un artículo o socio sin estos datos sigue funcionando igual en
 * todo lo que no sea facturación electrónica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('cabys_code', 13)->nullable()->after('barcode')
                ->comment('Código CAByS del BCCR, 13 dígitos; obligatorio para facturar el artículo');
            $table->string('fiscal_unit_code', 15)->nullable()->after('cabys_code')
                ->comment('Unidad de medida oficial v4.4 (Nota 15): Unid, kg, L, Sp...');
            $table->string('iva_rate_code', 2)->nullable()->after('fiscal_unit_code')
                ->comment('Código de tarifa de IVA v4.4 (Nota 8.1) por defecto del artículo');
        });

        Schema::table('business_partners', function (Blueprint $table) {
            $table->string('identification_type', 2)->nullable()->after('tax_id')
                ->comment('Nota 4: 01 física, 02 jurídica, 03 DIMEX, 04 NITE, 05 extranjero, 06 no contribuyente');
            $table->string('province', 60)->nullable()->after('phone');
            $table->string('canton', 60)->nullable()->after('province');
            $table->string('district', 60)->nullable()->after('canton');
            $table->string('address_details')->nullable()->after('district')->comment('Otras señas');
        });

        // Un emisor puede tener varias actividades inscritas en el RUT, y de
        // cuál se factura depende a qué cuenta de ingresos va la venta.
        Schema::create('company_economic_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 6)->comment('código de actividad económica de Hacienda');
            $table->string('name');
            $table->foreignId('revenue_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete()
                ->comment('cuenta de ingresos por ventas de esta actividad');
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active')->comment('active|inactive');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // Una venta de contado se cobra en el acto: cada medio de pago define
        // contra qué cuenta se debita (caja, banco, tarjetas por liquidar).
        Schema::create('billing_payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('method_code', 2)->comment('Nota 6: 01 efectivo, 02 tarjeta, 04 transferencia...');
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->timestamps();

            $table->unique(['company_id', 'method_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_accounts');
        Schema::dropIfExists('company_economic_activities');

        Schema::table('business_partners', function (Blueprint $table) {
            $table->dropColumn(['identification_type', 'province', 'canton', 'district', 'address_details']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['cabys_code', 'fiscal_unit_code', 'iva_rate_code']);
        });
    }
};
