<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo reparte el Superusuario el cupo de Conti entre las personas de su
 * licencia (CLAUDE.md secc. 32). El cupo es de la licencia y lo comparten
 * todas sus compañías y personas, el Superusuario incluido.
 *
 * - conti_user_settings: por persona y licencia, si puede usar Conti, sus
 *   límites en créditos (por día y por semana, sin pasar los de la licencia)
 *   y los modelos que puede elegir. Sin fila: puede, sin límite propio y con
 *   todos los modelos. El Superusuario nunca tiene fila: no se limita a sí
 *   mismo.
 * - company_invitations.conti_settings: lo mismo, elegido al invitar; se
 *   aplica cuando la persona acepta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conti_user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            // Vacío = sin límite propio (cuentan igual los de la licencia).
            $table->decimal('daily_credits', 10, 2)->nullable();
            $table->decimal('weekly_credits', 10, 2)->nullable();
            // Vacío = todos los de conti.models.
            $table->json('models')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['license_id', 'user_id']);
        });

        Schema::table('company_invitations', function (Blueprint $table) {
            $table->json('conti_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->dropColumn('conti_settings');
        });

        Schema::dropIfExists('conti_user_settings');
    }
};
