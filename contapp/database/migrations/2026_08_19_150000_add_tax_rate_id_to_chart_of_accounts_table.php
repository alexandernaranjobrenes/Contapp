<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')->nullable()->after('tax_classification')
                ->constrained('tax_rates')->nullOnDelete()
                ->comment('cuenta a la que se deriva el impuesto de esta tarifa (ej. IVA Soportado/Devengado 13%)');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_rate_id');
        });
    }
};
