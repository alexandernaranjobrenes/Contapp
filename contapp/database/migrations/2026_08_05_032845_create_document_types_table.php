<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 3)->comment('FVE, NCC, DVC, NDC, TRB, CKB, DEB, ADD, ADC, ACC');
            $table->string('name');
            $table->string('origin_module')->comment('ventas|compras|bancos|contable|cxc|cxp|activos_fijos');
            $table->boolean('generates_journal')->default(true);
            $table->foreignId('default_debit_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('default_credit_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('numbering_mask')->default('99999999');
            $table->unsignedTinyInteger('field_count')->default(8);
            $table->unsignedBigInteger('next_consecutive')->default(1);
            $table->unsignedBigInteger('range_from')->nullable();
            $table->unsignedBigInteger('range_to')->nullable();
            $table->boolean('consecutive_on_save')->default(false);
            $table->boolean('allow_out_of_range_dates')->default(false);
            $table->boolean('prevent_admins_out_of_range')->default(false);
            $table->date('date_range_from')->nullable();
            $table->date('date_range_to')->nullable();
            $table->string('currency_mode')->default('libre')->comment('local_fija|extranjera_fija|libre');
            $table->boolean('allows_balance_increase')->default(false);
            $table->boolean('reads_document_classifications')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
