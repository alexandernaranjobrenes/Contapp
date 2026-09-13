<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code')->comment('alfanumérico x-xxx');
            $table->string('name');
            $table->string('type')->comment('client|supplier|both');
            $table->string('tax_id')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('bp_categories')->nullOnDelete();
            $table->foreignId('family_id')->nullable()->constrained('bp_families')->nullOnDelete();
            $table->foreignId('gl_account_id')->constrained('chart_of_accounts');
            $table->foreignId('currency_id')->constrained();
            $table->decimal('credit_limit', 18, 2)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_partners');
    }
};
