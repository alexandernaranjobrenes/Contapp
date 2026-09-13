<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bp_payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_journal_entry_id')->constrained('journal_entries');
            $table->foreignId('open_item_id')->constrained('bp_open_items');
            $table->decimal('applied_amount', 18, 2);
            $table->date('applied_date');
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('realized_fx_difference', 18, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_payment_applications');
    }
};
