<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El canal de comentarios sobre CONTAPP y las noticias del backoffice
     * (FeedbackService). Global, sin compañía: es de toda la aplicación, no
     * de una licencia.
     *
     * feedback_comments guarda las publicaciones y sus respuestas en la misma
     * tabla (parent_id), así el id es único entre las dos y las imágenes del
     * bucket se llaman igual para ambas: comments/{id}_{n}.{ext}.
     *
     * - Autor: una cuenta de la aplicación (user_id) o el equipo de CONTAPP
     *   desde el backoffice (propietario_id). Uno de los dos.
     * - score: votos a favor menos votos en contra. Se recalcula desde
     *   feedback_votes en cada voto, y ordena la lista.
     * - status: 'open' (a la vista), 'solved' (el backoffice lo dio por
     *   resuelto) o 'removed' (lo quitó el backoffice, con su motivo). Los
     *   dos últimos ya no se ven en la aplicación, pero quedan registrados.
     */
    public function up(): void
    {
        Schema::create('feedback_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('feedback_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('propietario_id')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->text('body');
            $table->json('image_paths')->nullable();
            $table->integer('score')->default(0);
            $table->string('status', 10)->default('open');
            $table->timestamp('solved_at')->nullable();
            $table->foreignId('solved_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'status', 'score']);
        });

        // Un voto por cuenta y publicación: +1 o -1. Quitar el voto borra la fila.
        Schema::create('feedback_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('feedback_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('value');
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
        });

        // Las noticias que publica el backoffice. Una imagen como mucho:
        // news/{id}_1.{ext}. link_url es una dirección completa (https://…) o
        // una pantalla de CONTAPP (/dashboard).
        Schema::create('news_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('body');
            $table->string('link_url', 500)->nullable();
            $table->string('image_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_posts');
        Schema::dropIfExists('feedback_votes');
        Schema::dropIfExists('feedback_comments');
    }
};
