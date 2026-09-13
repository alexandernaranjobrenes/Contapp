<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('tax_id')->nullable()->comment('cédula jurídica');
            $table->string('country_code', 2)->default('CR');
            $table->foreignId('local_currency_id')->nullable()->constrained('currencies');
            $table->foreignId('foreign_currency_id')->nullable()->constrained('currencies');
            $table->foreignId('system_currency_id')->nullable()->constrained('currencies');
            $table->string('timezone')->default('America/Costa_Rica');
            $table->string('logo_path')->nullable();
            $table->string('status')->default('active')->comment('active|suspended');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
