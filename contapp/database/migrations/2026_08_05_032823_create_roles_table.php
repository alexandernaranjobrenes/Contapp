<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete()
                ->comment('null = rol de sistema global');
            $table->string('name');
            $table->string('type')->comment('super_admin|admin|user');
            $table->boolean('can_grant_permissions')->default(false)
                ->comment('delega la facultad de otorgar derechos, nunca delete');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
