<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->foreignId('business_partner_id')->nullable()->constrained('business_partners')->nullOnDelete();
            $table->decimal('debit_local', 18, 2)->default(0);
            $table->decimal('credit_local', 18, 2)->default(0);
            $table->decimal('debit_foreign', 18, 2)->default(0);
            $table->decimal('credit_foreign', 18, 2)->default(0);
            $table->foreignId('currency_id')->nullable()->constrained();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
