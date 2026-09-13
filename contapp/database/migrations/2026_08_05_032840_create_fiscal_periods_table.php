<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period_number');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('open')->comment('open|blocked|closed');
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'period_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
