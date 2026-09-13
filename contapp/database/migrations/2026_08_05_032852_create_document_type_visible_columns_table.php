<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_visible_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('column_key');
            $table->boolean('visible')->default(true);
            $table->string('language', 2)->default('es');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_visible_columns');
    }
};
