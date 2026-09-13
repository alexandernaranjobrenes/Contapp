<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_profile_id')->constrained('commercial_profiles')->cascadeOnDelete();
            $table->date('next_action_date');
            $table->string('action_type')->comment('ej. "llamar antes de renovar", "confirmar aumento de cupo"');
            $table->string('status')->default('pending')->comment('pending|completed');
            $table->timestamps();

            $table->index(['commercial_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_follow_ups');
    }
};
