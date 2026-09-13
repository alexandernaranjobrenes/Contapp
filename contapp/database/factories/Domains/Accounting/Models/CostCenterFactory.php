<?php

namespace Database\Factories\Domains\Accounting\Models;

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CostCenterFactory extends Factory
{
    protected $model = CostCenter::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->numerify('CC-##'),
            'name' => fake()->words(2, true),
            'start_date' => now()->subYear()->format('Y-m-d'),
            'end_date' => null,
            'is_active' => true,
        ];
    }
}
