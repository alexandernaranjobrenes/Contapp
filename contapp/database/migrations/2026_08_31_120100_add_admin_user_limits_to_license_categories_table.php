<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_admins')->default(3)->after('max_companies');
            $table->unsignedSmallInteger('max_users')->default(10)->after('max_admins');
        });
    }

    public function down(): void
    {
        Schema::table('license_categories', function (Blueprint $table) {
            $table->dropColumn(['max_admins', 'max_users']);
        });
    }
};
