<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * business_partners no existía cuando se crearon journal_entries/journal_details
     * (Fase 0); ahora que existe, se agrega la FK que ya estaba documentada como
     * pendiente en esas migraciones.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign('business_partner_id')->references('id')->on('business_partners')->nullOnDelete();
        });

        Schema::table('journal_details', function (Blueprint $table) {
            $table->foreign('business_partner_id')->references('id')->on('business_partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['business_partner_id']);
        });

        Schema::table('journal_details', function (Blueprint $table) {
            $table->dropForeign(['business_partner_id']);
        });
    }
};
