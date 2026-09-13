<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('is_reconciliation_type')->default(false)->after('is_opening_type')
                ->comment('true solo en el tipo de documento reservado que el sistema crea de oficio para traspasos de reconciliación interna (código ARR, ver AccountReconciliationController) — no seleccionable en el asiento manual');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('is_reconciliation_type');
        });
    }
};
