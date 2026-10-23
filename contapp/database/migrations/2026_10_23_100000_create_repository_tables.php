<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El Repositorio: documentos de consulta (leyes, reglamentos, resoluciones
     * de Hacienda, artículos) que el backoffice sube en Markdown y agrupa en
     * carpetas. Globales, sin compañía. Cada documento se activa por separado
     * para que Conti lo consulte y para que las cuentas lo vean como PDF.
     *
     * El archivo vive en el disco «repositories» (public/repositories); acá
     * va su ruta. Una carpeta con documentos no se puede borrar.
     */
    public function up(): void
    {
        Schema::create('repository_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('repository_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained('repository_folders')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('file_path')->unique();
            $table->string('original_name');
            $table->unsignedInteger('size');
            $table->boolean('conti_enabled')->default(false);
            $table->boolean('public_enabled')->default(false);
            // Cuándo se subió el archivo que tiene ahora: lo que las cuentas
            // ven como «Actualizado». Activarlo o cambiarle el título no cuenta.
            $table->timestamp('file_updated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->timestamps();

            $table->index(['public_enabled', 'folder_id']);
            $table->index('conti_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_documents');
        Schema::dropIfExists('repository_folders');
    }
};
