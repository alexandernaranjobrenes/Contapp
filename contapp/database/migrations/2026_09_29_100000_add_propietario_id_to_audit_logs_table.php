<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quién hizo una acción del Propietario (CLAUDE.md secc. 11/14). La
     * bitácora nació para el plano del cliente —company_id y user_id apuntan
     * a una compañía y a un usuario de ella— y el Propietario vive en su
     * propia tabla: meterlo en user_id (que apunta a users) rompería la
     * separación de los dos planos. En una fila del Propietario, company_id
     * y user_id quedan nulos.
     *
     * nullOnDelete igual que user_id y company_id: dar de baja una cuenta de
     * Propietario no puede borrar la historia de lo que hizo.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('propietario_id')->nullable()->after('user_id')
                ->constrained('propietarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('propietario_id');
        });
    }
};
