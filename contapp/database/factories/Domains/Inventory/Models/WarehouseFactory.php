<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(2, true),
            'address' => null,
            'is_default' => false,
            'status' => 'active',
        ];
    }
}
