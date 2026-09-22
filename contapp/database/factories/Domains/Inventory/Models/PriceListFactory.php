<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

class PriceListFactory extends Factory
{
    protected $model = PriceList::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->lexify('LP-???')),
            'name' => fake()->words(2, true),
            'currency_id' => Currency::factory(),
            'prices_include_tax' => false,
            'valid_from' => null,
            'valid_to' => null,
            'is_default' => false,
            'status' => 'active',
        ];
    }

    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }
}
