<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La asignación de una licencia a una persona desde el backoffice, a la
     * espera de que la acepte desde el correo (LicenseInvitationService).
     *
     * Una por licencia: cambiar los datos o reenviar el correo actualiza la
     * misma fila; quitar la asignación la borra (la bitácora guarda el rastro).
     *
     * - account_type 'existing': una cuenta que ya existe (user_id).
     *   'new': una persona sin cuenta; la cuenta se crea al aceptar, con
     *   name y email de acá y la contraseña que elija.
     * - token_hash: el enlace del correo lleva el token; acá solo su hash,
     *   como los de recuperación de contraseña. Cada envío lo cambia, así
     *   que el enlace anterior deja de servir.
     * - expires_at: hasta cuándo sirve el último enlace enviado.
     */
    public function up(): void
    {
        Schema::create('license_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('account_type', 10);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_invitations');
    }
};
