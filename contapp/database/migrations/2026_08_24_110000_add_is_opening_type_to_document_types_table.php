<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('is_opening_type')->default(false)->after('bp_line_requirement')
                ->comment('true solo en el tipo de documento reservado que el sistema crea de oficio para la carga de saldos iniciales (ver OpeningBalanceBulkImporter) — no seleccionable en el asiento manual');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('is_opening_type');
        });
    }
};
