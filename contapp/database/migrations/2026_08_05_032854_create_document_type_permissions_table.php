<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type')->comment('user|role');
            $table->unsignedBigInteger('subject_id');
            $table->string('scope')->default('individual')->comment('individual|group');
            $table->boolean('can_create')->default(false);
            $table->boolean('can_modify')->default(false);
            $table->boolean('can_delete')->default(false)
                ->comment('UI lo respeta; borrado real de contabilizado solo lo fuerza el super admin desde código');
            $table->boolean('can_void')->default(false);
            $table->boolean('can_vary_consecutive')->default(false);
            $table->boolean('can_backdate')->default(false);
            $table->boolean('can_modify_integrated_documents')->default(false);
            $table->boolean('can_create_out_of_range')->default(false);
            $table->boolean('can_modify_document_dates')->default(false);
            $table->timestamps();

            $table->unique(['document_type_id', 'subject_type', 'subject_id'], 'document_type_permissions_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_permissions');
    }
};
