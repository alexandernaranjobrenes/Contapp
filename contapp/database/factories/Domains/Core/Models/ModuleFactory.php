<?php

namespace Database\Factories\Domains\Core\Models;

use App\Domains\Core\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->word(),
            'name' => fake()->words(3, true),
        ];
    }
}
