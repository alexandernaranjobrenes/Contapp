<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bp_open_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_partner_id')->constrained('business_partners')->cascadeOnDelete();
            $table->foreignId('origin_journal_detail_id')->constrained('journal_details');
            $table->string('document_type_code', 3);
            $table->unsignedBigInteger('document_number');
            $table->date('due_date')->nullable();
            $table->decimal('original_amount', 18, 2);
            $table->foreignId('currency_id')->constrained();
            $table->decimal('applied_amount', 18, 2)->default(0);
            $table->decimal('balance', 18, 2);
            $table->string('status')->default('open')->comment('open|partial|closed');
            $table->timestamps();

            $table->index(['business_partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_open_items');
    }
};
