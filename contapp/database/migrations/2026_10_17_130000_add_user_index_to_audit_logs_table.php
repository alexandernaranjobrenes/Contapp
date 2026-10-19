<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El filtro «Usuario» de la Bitácora (CLAUDE.md secc. 35): lo que hizo
     * una persona en una compañía, de a 15 y de lo más nuevo a lo más viejo
     * (company_id, user_id, id), y quiénes aparecen en ella (el mismo índice,
     * sin el id).
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['company_id', 'user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'user_id', 'id']);
        });
    }
};
