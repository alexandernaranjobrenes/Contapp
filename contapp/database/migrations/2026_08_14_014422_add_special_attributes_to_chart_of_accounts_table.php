<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->boolean('requires_business_partner')->default(false)->after('accepts_posting')
                ->comment('obliga a indicar socio de negocio en cada línea, ej. CxC/CxP');
            $table->boolean('is_cash_account')->default(false)->after('requires_business_partner')
                ->comment('cuenta monetaria: elegible para vincularse a bank_accounts');
            $table->boolean('requires_cost_center')->default(false)->after('is_cash_account')
                ->comment('obliga norma de reparto/centro de costo; reservado, el módulo de centros de costo aún no existe');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn(['requires_business_partner', 'is_cash_account', 'requires_cost_center']);
        });
    }
};
