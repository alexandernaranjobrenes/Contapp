<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conti, el asistente de CONTAPP (CLAUDE.md secc. 32).
 *
 * - conti_tokens: el pase con el que el agente de n8n consulta la API de
 *   Conti a nombre de una persona, en una compañía. Se emite por mensaje y
 *   dura minutos; se guarda solo su hash.
 * - conti_actions: lo que Conti prepara para guardar. Nada se escribe hasta
 *   que la persona lo confirma en CONTAPP; la fila queda como registro de
 *   qué se pidió, quién lo confirmó y qué resultó.
 */
return new class extends Migration
{
    public function up(): void
    {
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

        Schema::create('conti_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 60);
            $table->json('input');
            $table->json('summary');
            $table->char('payload_hash', 64);
            // pending → confirmed | discarded | failed; expired se deduce de expires_at.
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conti_actions');
        Schema::dropIfExists('conti_tokens');
    }
};
