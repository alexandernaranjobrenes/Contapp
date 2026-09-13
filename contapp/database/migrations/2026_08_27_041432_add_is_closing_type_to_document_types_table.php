<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('is_closing_type')->default(false)->after('is_reconciliation_type')
                ->comment('true solo en el tipo de documento reservado que el sistema crea de oficio para el asiento de cierre anual (código ACC, ver PeriodCloseService::closeYear()) — no seleccionable en el asiento manual, y LedgerService lo excluye del mayor auxiliar de cuentas de resultados para no esconder la actividad real del año detrás del asiento que la cancela');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('is_closing_type');
        });
    }
};
