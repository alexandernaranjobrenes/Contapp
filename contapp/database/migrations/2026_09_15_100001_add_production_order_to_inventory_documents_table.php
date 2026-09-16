<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emisiones y recibos de producción son movimientos de stock como cualquier
 * otro; lo que los distingue es contra qué orden acumulan (o descargan) el
 * costo en proceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->foreignId('production_order_id')->nullable()->after('business_partner_id')
                ->constrained()->nullOnDelete()
                ->comment('obligatorio en production_issue y production_receipt, null en el resto');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_order_id');
        });
    }
};
