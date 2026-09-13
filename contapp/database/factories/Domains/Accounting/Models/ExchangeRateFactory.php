<?php

namespace Database\Factories\Domains\Accounting\Models;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        $usd = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'Dólar estadounidense', 'symbol' => '$', 'decimal_places' => 2]
        );

        return [
            'company_id' => Company::factory(),
            'currency_id' => $usd->id,
            'rate_date' => now()->format('Y-m-d'),
            'rate_type' => 'reference',
            'rate' => '520.000000',
            'source' => 'manual',
            'is_locked' => false,
        ];
    }
}
