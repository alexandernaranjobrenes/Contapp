<?php

namespace Database\Factories\Domains\Licensing\Models;

use App\Domains\Licensing\Models\LicenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class LicenseCategoryFactory extends Factory
{
    protected $model = LicenseCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Básica', 'Profesional', 'Corporativa']).' '.fake()->unique()->numerify('##'),
            'max_companies' => fake()->numberBetween(1, 10),
            'max_admins' => fake()->numberBetween(1, 5),
            'max_users' => fake()->numberBetween(5, 20),
            'duration_months' => 12,
            'description' => null,
            'is_active' => true,
        ];
    }
}
