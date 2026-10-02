<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La foto de perfil. `photo_path` es la ruta dentro del disco de imágenes
     * (MediaStorage), siempre «users/profile_{id}.{ext}»: una por cuenta, con
     * nombre fijo.
     *
     * `photo_updated_at` existe por ese nombre fijo. Al cambiar la foto la
     * dirección pública es la misma, y el navegador seguiría mostrando la
     * anterior desde su caché: la fecha va en la dirección (?v=…) para que
     * cada foto nueva sea, para el navegador, una dirección distinta.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('email');
            $table->timestamp('photo_updated_at')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'photo_updated_at']);
        });
    }
};
