<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

class UnitOfMeasureFactory extends Factory
{
    protected $model = UnitOfMeasure::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'name' => fake()->words(2, true),
            'decimals' => 2,
            'status' => 'active',
        ];
    }
}
