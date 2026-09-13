<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->string('email')->nullable()->after('tax_id');
            $table->string('economic_activity_code')->nullable()->after('email')
                ->comment('Código de actividad económica de Hacienda, para facturación electrónica');
            $table->string('phone')->nullable()->after('economic_activity_code');
            $table->string('contact_name')->nullable()->after('phone')->comment('Nombre del encargado');
            $table->date('partner_since')->nullable()->after('contact_name')->comment('Fecha de inicio como cliente/proveedor');
        });
    }

    public function down(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->dropColumn(['email', 'economic_activity_code', 'phone', 'contact_name', 'partner_since']);
        });
    }
};
