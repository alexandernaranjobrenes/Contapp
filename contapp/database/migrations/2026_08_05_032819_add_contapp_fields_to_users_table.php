<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password')
                ->comment('global, no editable por UI normal');
            $table->foreignId('default_company_id')->nullable()->after('is_super_admin')
                ->constrained('companies')->nullOnDelete();
            $table->string('status')->default('active')->after('default_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_company_id');
            $table->dropColumn(['is_super_admin', 'status']);
        });
    }
};
