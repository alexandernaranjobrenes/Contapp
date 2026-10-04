<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permisos por pantalla del menú, e invitaciones a una compañía.
     *
     * screen_permissions: el nivel ('read' | 'read_write') de una cuenta en una
     * pantalla del menú lateral (App\Domains\Core\Support\ScreenCatalog), en
     * una compañía. Sin fila, sin acceso. Convive con module_permissions: un
     * permiso por módulo, el esquema anterior, vale para todas las pantallas
     * de ese módulo (ver ScreenAccessService). Al guardar los permisos de
     * alguien con el editor nuevo, los suyos por módulo se reemplazan por
     * filas por pantalla.
     *
     * company_invitations: la invitación de un Superusuario o Administrador a
     * una persona —tenga o no cuenta en CONTAPP— para trabajar en una
     * compañía (CompanyInvitationService). Hasta que la acepta desde el correo
     * no entra: la cuenta (si es nueva), el acceso a la compañía, el rol y los
     * permisos se crean al aceptar, con lo que guarda esta fila.
     */
    public function up(): void
    {
        Schema::create('screen_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('screen', 80);
            $table->string('access_level', 10);
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'screen']);
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role_type', 10);
            $table->json('screen_permissions');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
        Schema::dropIfExists('screen_permissions');
    }
};
