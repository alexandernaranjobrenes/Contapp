<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tema visual de cada compañía (CLAUDE.md secc. 31). Nulo = el
 * predeterminado (Marino), así las compañías que ya existen no cambian de
 * aspecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('theme', 20)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
