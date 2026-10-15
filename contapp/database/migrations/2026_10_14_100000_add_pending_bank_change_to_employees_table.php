<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El cambio de la cuenta bancaria de un empleado, esperando que lo
     * apruebe el Superusuario (EmployeeBankChangeService, CLAUDE.md secc. 36).
     * Mientras tanto, la planilla y el archivo de pago siguen con la cuenta
     * confirmada (bank_name, bank_account): cambiarle la cuenta a alguien
     * justo antes de pagar es el fraude más común en una planilla.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('pending_bank_name')->nullable()->after('bank_account');
            $table->string('pending_bank_account', 34)->nullable()->after('pending_bank_name');
            $table->foreignId('bank_change_requested_by')->nullable()->after('pending_bank_account')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('bank_change_requested_at')->nullable()->after('bank_change_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_change_requested_by');
            $table->dropColumn(['pending_bank_name', 'pending_bank_account', 'bank_change_requested_at']);
        });
    }
};
