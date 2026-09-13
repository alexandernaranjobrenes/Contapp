<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla deliberadamente separada de users (CLAUDE.md secc. 11/14): el
     * Propietario es el fabricante/distribuidor de CONTAPP, un actor sin
     * relación con las compañías/licencias que administra más que ser quien
     * las opera — nunca comparte guard, sesión ni fila con los usuarios de
     * una compañía cliente.
     */
    public function up(): void
    {
        Schema::create('propietarios', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('propietarios');
    }
};
