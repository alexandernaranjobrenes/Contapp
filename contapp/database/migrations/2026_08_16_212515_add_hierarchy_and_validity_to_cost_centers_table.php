<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('company_id')
                ->constrained('cost_centers')->nullOnDelete();
            $table->unsignedTinyInteger('level')->default(1)->after('code')
                ->comment('1 a 3; código xx-xx-xx, un segmento de 2 dígitos por nivel');
            $table->date('start_date')->after('name')->comment('vigente desde');
            $table->date('end_date')->nullable()->after('start_date')->comment('vigente hasta; null = sin fecha de fin definida');
        });
    }

    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['level', 'start_date', 'end_date']);
        });
    }
};
