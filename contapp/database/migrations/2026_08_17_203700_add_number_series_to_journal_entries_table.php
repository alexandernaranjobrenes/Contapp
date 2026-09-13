<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('number_series_id')->nullable()->after('document_number')
                ->constrained('document_type_number_series')->nullOnDelete();
            $table->unsignedBigInteger('series_number')->nullable()->after('number_series_id')
                ->comment('número manual asignado dentro de number_series_id; document_number sigue siendo el consecutivo interno automático');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('number_series_id');
            $table->dropColumn('series_number');
        });
    }
};
