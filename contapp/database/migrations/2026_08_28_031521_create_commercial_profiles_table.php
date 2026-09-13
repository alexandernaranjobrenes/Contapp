<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_profiles', function (Blueprint $table) {
            $table->id();
            // Ancla por licencia, no por "superusuario_id" (CLAUDE.md secc.
            // 15): en este proyecto una licencia no tiene un único Superusuario
            // fijo (cada activación crea su propio admin de compañía, ver
            // docs/decisiones.md sobre esa divergencia) — la licencia es la
            // identidad de "cliente" real y estable en este esquema.
            $table->foreignId('license_id')->unique()->constrained('licenses')->cascadeOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('commercial_email')->nullable()->comment('puede diferir del correo de acceso al sistema');
            $table->string('referral_source')->nullable()->comment('referido, campaña, prospección directa...');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_profiles');
    }
};
