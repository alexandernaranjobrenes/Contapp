<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type')->comment('user|role');
            $table->unsignedBigInteger('subject_id');
            $table->string('access_level')->comment('read_write|read|none');
            $table->timestamps();

            $table->unique(['company_id', 'module_id', 'subject_type', 'subject_id'], 'module_permissions_subject_unique');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_permissions');
    }
};
