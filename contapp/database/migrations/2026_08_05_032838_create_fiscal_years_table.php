<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status')->default('open')->comment('open|closed');
            $table->timestamps();

            $table->unique(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
