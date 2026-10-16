<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las importaciones de respaldos (CLAUDE.md secc. 37). Un respaldo se
     * sube, se revisa (se descifra y se verifica la firma, sin guardar nada)
     * y, confirmado, se importa en segundo plano a una compañía nueva.
     *
     * - file_path: el archivo subido, tal cual (cifrado), en el disco privado.
     *   Se borra al terminar o al vencer.
     * - secret: la contraseña del archivo, cifrada con la APP_KEY, solo
     *   mientras hace falta para importarlo. Se borra al terminar.
     * - summary: lo que dice el respaldo (compañía, período, secciones,
     *   cantidades); result: lo que se importó y lo que quedó fuera.
     */
    public function up(): void
    {
        Schema::create('company_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by_propietario_id')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->string('status', 20)->default('validated')->comment('validated|queued|importing|done|failed|expired');
            $table->string('file_path')->nullable();
            $table->text('secret')->nullable();
            $table->json('summary');
            $table->json('company_data')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['license_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_backups');
    }
};
