<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_type_number_series', function (Blueprint $table) {
            $table->string('holder_name')->nullable()->after('name')
                ->comment('a quién se le entregó esta serie/talonario, ej. "Pedro Jiménez" — para identificar quién cobró');
        });
    }

    public function down(): void
    {
        Schema::table('document_type_number_series', function (Blueprint $table) {
            $table->dropColumn('holder_name');
        });
    }
};
