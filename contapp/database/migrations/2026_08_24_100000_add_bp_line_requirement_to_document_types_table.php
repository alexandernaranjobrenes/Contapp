<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('bp_line_requirement', 20)->default('none')->after('requires_electronic_key')
                ->comment('none|due_date|application|either — qué exige este tipo de documento en sus líneas con socio de negocio');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('bp_line_requirement');
        });
    }
};
