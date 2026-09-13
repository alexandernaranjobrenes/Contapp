<?php

namespace Database\Seeders;

use App\Domains\Accounting\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Catálogo mínimo de monedas para Costa Rica: CRC como moneda local típica,
 * USD como moneda extranjera y de sistema (ver docs/decisiones.md, 2026-08-04).
 * Cada Company elige su rol vía local_currency_id/foreign_currency_id/system_currency_id;
 * esta tabla es global al tenant y no se repite por compañía.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::firstOrCreate(
            ['code' => 'CRC'],
            ['name' => 'Colón costarricense', 'symbol' => '₡', 'decimal_places' => 2]
        );

        Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'Dólar estadounidense', 'symbol' => '$', 'decimal_places' => 2]
        );
    }
}
