<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\ItemGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemGroupFactory extends Factory
{
    protected $model = ItemGroup::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(2, true),
            'status' => 'active',
        ];
    }
}
