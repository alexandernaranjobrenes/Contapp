<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('código que el cliente activa, ej. CONTAPP-XXXX-XXXX-XXXX');
            $table->unsignedSmallInteger('max_companies')->default(1);
            $table->date('expires_at');
            $table->string('status')->default('active')->comment('active|revoked');
            $table->string('notes')->nullable()->comment('referencia interna: cliente, convenio, contacto...');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
