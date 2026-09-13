<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Habilita indicadores de impuesto PROPIOS por compañía, sin tocar el
 * catálogo nacional (company_id NULL = compartido, editable solo por el
 * Propietario — ver TaxRateController). company_id NULL en ambas tablas
 * sigue siendo el catálogo global de siempre; un valor real es un
 * tipo/indicador que un Superusuario/Administrador creó solo para su propia
 * compañía (ver App\Domains\Core\Scopes\GlobalOrOwnCompanyScope).
 *
 * El unique(code) global de tax_types se suelta: el código ahora se valida
 * como único a nivel de aplicación, alcanzado por company_id (NULL para el
 * catálogo nacional, el id de la compañía para uno propio) — igual criterio
 * que ya usa tax_rates.code, que nunca tuvo constraint de BD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_types', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('tax_rates', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('tax_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->unique('code');
        });
    }
};
