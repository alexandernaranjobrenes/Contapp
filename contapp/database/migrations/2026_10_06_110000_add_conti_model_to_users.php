<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El modelo de OpenAI con el que Conti le responde a cada persona: lo elige
 * ella en el chat (CLAUDE.md secc. 32), entre los de conti.models. Vacío = el
 * predeterminado de la instalación (OPENAI_MODEL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('conti_model', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('conti_model');
        });
    }
};
