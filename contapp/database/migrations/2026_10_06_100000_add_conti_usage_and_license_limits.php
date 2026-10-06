<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conti pasa de n8n al código, con OpenAI (CLAUDE.md secc. 32;
 * docs/decisiones.md, 2026-10-06).
 *
 * - conti_tokens se va: el agente ya no consulta una API con un pase, sus
 *   herramientas corren dentro de CONTAPP.
 * - conti_usage: lo que consumió cada mensaje (tokens, costo y créditos),
 *   sin el texto de la conversación. Con esto se aplican los límites por
 *   licencia y el backoffice ve el consumo.
 * - En licencias y categorías: si la licencia tiene Conti y sus límites en
 *   créditos (por día, por semana y por persona al día). La categoría trae
 *   los valores con que nace cada licencia, igual que los cupos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('conti_tokens');

        Schema::create('conti_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 80);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedSmallInteger('tool_calls')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('credits', 12, 4)->default(0);
            // ok | error (se consumió, pero la respuesta no llegó a la persona).
            $table->string('status', 20)->default('ok');
            $table->timestamp('created_at')->nullable();

            $table->index(['license_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        foreach (['licenses', 'license_categories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('ai_enabled')->default(false);
                // Vacío = sin límite.
                $table->decimal('ai_daily_credits', 10, 2)->nullable();
                $table->decimal('ai_weekly_credits', 10, 2)->nullable();
                $table->decimal('ai_user_daily_credits', 10, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['licenses', 'license_categories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['ai_enabled', 'ai_daily_credits', 'ai_weekly_credits', 'ai_user_daily_credits']);
            });
        }

        Schema::dropIfExists('conti_usage');

        // La tabla como la dejó 2026_10_05_100000_create_conti_tables.
        Schema::create('conti_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'expires_at']);
        });
    }
};
