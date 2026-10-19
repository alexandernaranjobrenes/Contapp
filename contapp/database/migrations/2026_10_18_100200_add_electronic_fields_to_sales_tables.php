<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el XML v4.4 pide y el comprobante todavía no guardaba.
 *
 * - sales_documents.issued_at: FechaEmision lleva fecha Y HORA, y la clave
 *   numérica se arma con esa misma fecha (Hacienda rechaza «la fecha de la
 *   clave no concuerda con la fecha de emisión»). document_date sigue siendo
 *   la fecha del documento para la contabilidad.
 * - sale_condition_other / method_other: CondicionVentaOtros y
 *   MedioPagoOtros, obligatorios cuando la condición o el medio es 99.
 * - sales_references.issued_at pasa a fecha y hora: FechaEmisionIR es un
 *   dateTime, y es obligatorio.
 * - Las unidades de medida de los artículos que el catálogo anterior tenía
 *   mal escritas («kg», «m2»…) pasan al símbolo del XSD. Sin esto, el primer
 *   comprobante con ese artículo lo rechazaría el esquema. Los comprobantes ya
 *   emitidos no se tocan: son lo que se emitió.
 */
return new class extends Migration
{
    /** Símbolo anterior → símbolo del XSD (UnidadMedidaType). Null: no tiene equivalente. */
    private const UNITS = [
        'kg' => 'Kg',
        'km' => 'Km',
        'm' => 'M',
        'm2' => 'm²',
        'm3' => 'm³',
        'min' => 'Min',
        'mm' => 'Mm',
        // En el catálogo anterior «Cm» era centímetro; en el de Hacienda es
        // «Comisiones», y el centímetro es «cm».
        'Cm' => 'cm',
        // «t» era tonelada; en el XSD es tesla, y la tonelada es «T».
        't' => 'T',
        'Cm2' => null,
        'Cm3' => null,
    ];

    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dateTime('issued_at')->nullable()->after('document_date')
                ->comment('FechaEmision: fecha y hora con que se armó la clave');
            $table->string('sale_condition_other', 100)->nullable()->after('sale_condition')
                ->comment('CondicionVentaOtros: obligatorio con la condición 99');
        });

        Schema::table('sales_payments', function (Blueprint $table) {
            $table->string('method_other', 100)->nullable()->after('method_code')
                ->comment('MedioPagoOtros: obligatorio con el medio 99');
        });

        Schema::table('sales_references', function (Blueprint $table) {
            $table->dateTime('issued_at')->nullable()->change();
        });

        foreach (self::UNITS as $from => $to) {
            // whereRaw con BINARY no existe en SQLite: se compara exacto en PHP
            // para no confundir «Cm» con «cm» en una colación sin mayúsculas.
            $ids = DB::table('items')->where('fiscal_unit_code', $from)->get(['id', 'fiscal_unit_code'])
                ->filter(fn ($row) => $row->fiscal_unit_code === $from)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table('items')->whereIn('id', $ids)->update(['fiscal_unit_code' => $to]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sales_references', function (Blueprint $table) {
            $table->date('issued_at')->nullable()->change();
        });

        Schema::table('sales_payments', function (Blueprint $table) {
            $table->dropColumn('method_other');
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn(['issued_at', 'sale_condition_other']);
        });
    }
};
