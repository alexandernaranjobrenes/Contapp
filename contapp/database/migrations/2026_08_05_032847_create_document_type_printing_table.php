<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_printing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('workstation')->nullable();
            $table->string('assigned_printer')->nullable();
            $table->boolean('print_on_save')->default(false);
            $table->boolean('enable_direct_print')->default(false);
            $table->boolean('confirm_on_reprint')->default(false);
            $table->boolean('resume_lines_on_print')->default(false);
            $table->string('print_format_file')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_printing');
    }
};
