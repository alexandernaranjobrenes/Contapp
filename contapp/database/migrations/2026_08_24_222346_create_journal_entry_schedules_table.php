<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained();
            $table->string('description')->nullable();
            // Plantilla de líneas (cuenta, socio, moneda, débito/crédito,
            // centro de costo, descripción) — mismo shape que JournalLineInput,
            // guardado tal cual porque cada corrida solo genera un BORRADOR
            // (PostJournalService::saveDraft() ya no exige que cuadre ni que
            // las cuentas acepten todavía el resto de validaciones de post()).
            $table->json('lines');
            $table->string('frequency_type')->comment('days|months');
            $table->unsignedInteger('interval_count');
            $table->date('next_run_date');
            $table->date('expires_at')->nullable();
            $table->string('status')->default('active')->comment('active|expired|cancelled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_schedules');
    }
};
