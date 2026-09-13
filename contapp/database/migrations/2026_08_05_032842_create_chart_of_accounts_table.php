<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code')->comment('x-xx-xx-xx-xxx');
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('description_es');
            $table->string('description_en')->nullable();
            $table->string('account_type')->comment('asset|liability|equity|income|expense');
            $table->string('normal_balance')->comment('debit|credit');
            $table->string('currency_mode')->default('local')->comment('local|foreign|both');
            $table->boolean('accepts_posting')->default(false)
                ->comment('solo cuentas hoja reciben movimiento');
            $table->boolean('is_financial_report')->default(true);
            $table->string('section')->nullable();
            $table->integer('list_order')->default(0);
            $table->string('tax_classification')->default('none')
                ->comment('none|sales|purchases|iva_general|iva_devengado|iva_soportado');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
