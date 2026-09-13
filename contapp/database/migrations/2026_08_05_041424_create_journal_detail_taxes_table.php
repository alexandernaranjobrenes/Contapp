<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_detail_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_detail_id')->constrained('journal_details')->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->constrained('tax_rates');
            $table->decimal('taxable_base', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_detail_taxes');
    }
};
