<?php

namespace Database\Factories\Domains\BusinessPartners\Models;

use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class BpCategoryFactory extends Factory
{
    protected $model = BpCategory::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->lexify('CAT-???'),
            'name' => fake()->words(2, true),
        ];
    }
}
