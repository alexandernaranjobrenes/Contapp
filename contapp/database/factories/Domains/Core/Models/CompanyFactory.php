<?php

namespace Database\Factories\Domains\Core\Models;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        // CRC/USD son catálogo global compartido entre todas las compañías del
        // tenant: reutilizar, nunca duplicar (code es unique en currencies).
        $crc = Currency::firstOrCreate(
            ['code' => 'CRC'],
            ['name' => 'Colón costarricense', 'symbol' => '₡', 'decimal_places' => 2]
        );

        $usd = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'Dólar estadounidense', 'symbol' => '$', 'decimal_places' => 2]
        );

        return [
            'legal_name' => fake()->company().' S.A.',
            'trade_name' => fake()->company(),
            'tax_id' => fake()->numerify('#-###-######'),
            'address' => fake()->address(),
            'country_code' => 'CR',
            'local_currency_id' => $crc->id,
            'foreign_currency_id' => $usd->id,
            'system_currency_id' => $usd->id,
            'timezone' => 'America/Costa_Rica',
            'status' => 'active',
        ];
    }
}
