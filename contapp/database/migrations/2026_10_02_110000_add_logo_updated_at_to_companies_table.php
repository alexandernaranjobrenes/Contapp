<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuándo se cambió el logo por última vez. `logo_path` ya existía; el
     * logo se guarda con nombre fijo
     * («companies/user_owner_{id}/company_logo_{id}.{ext}»), así que al
     * reemplazarlo la dirección pública es la misma. La fecha va en la
     * dirección (?v=…) para que el navegador no muestre el anterior desde su
     * caché. Mismo criterio que users.photo_updated_at.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('logo_updated_at')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('logo_updated_at');
        });
    }
};
