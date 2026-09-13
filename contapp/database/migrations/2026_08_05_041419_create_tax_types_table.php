<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->comment('IVA, RENTA...');
            $table->string('name');
            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_types');
    }
};
