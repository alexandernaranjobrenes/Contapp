<?php

namespace Database\Factories\Domains\Accounting\Models;

use App\Domains\Accounting\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->word(),
            'symbol' => '$',
            'decimal_places' => 2,
        ];
    }

    public function crc(): static
    {
        return $this->state(fn () => [
            'code' => 'CRC',
            'name' => 'Colón costarricense',
            'symbol' => '₡',
        ]);
    }

    public function usd(): static
    {
        return $this->state(fn () => [
            'code' => 'USD',
            'name' => 'Dólar estadounidense',
            'symbol' => '$',
        ]);
    }
}
