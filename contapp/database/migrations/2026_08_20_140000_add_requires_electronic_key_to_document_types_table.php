<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('requires_electronic_key')->default(false)->after('generates_journal')
                ->comment('exige la clave numérica de 50 dígitos de Hacienda al registrar el documento');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('requires_electronic_key');
        });
    }
};
