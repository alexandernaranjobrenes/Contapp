<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_profile_id')->constrained('commercial_profiles')->cascadeOnDelete();
            $table->string('type')->comment('call|email|meeting|whatsapp|other');
            $table->date('occurred_at');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('summary');
            $table->timestamps();

            $table->index(['commercial_profile_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_interactions');
    }
};
