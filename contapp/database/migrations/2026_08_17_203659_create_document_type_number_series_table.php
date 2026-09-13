<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('ej. "Serie A", "Caja principal"');
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->unsignedBigInteger('next_number')->comment('siguiente número a asignar dentro del rango');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'document_type_id', 'name'], 'doc_type_number_series_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_number_series');
    }
};
