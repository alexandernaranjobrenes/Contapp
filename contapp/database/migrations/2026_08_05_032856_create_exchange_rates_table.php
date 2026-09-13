<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained();
            $table->date('rate_date');
            $table->string('rate_type')->comment('buy|sell|reference');
            $table->decimal('rate', 18, 6);
            $table->string('source')->default('manual')->comment('bccr_api|manual');
            $table->boolean('is_locked')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'currency_id', 'rate_date', 'rate_type'], 'exchange_rates_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
