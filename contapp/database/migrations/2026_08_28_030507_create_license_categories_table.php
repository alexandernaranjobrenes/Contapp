<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('nombre comercial, ej. Básica/Profesional/Corporativa');
            $table->unsignedSmallInteger('max_companies');
            $table->unsignedSmallInteger('duration_months')->default(12)
                ->comment('duración estándar, para permitir categorías con vigencias distintas a la anual');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_categories');
    }
};
