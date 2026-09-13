<?php

namespace Database\Factories\Domains\Licensing\Models;

use App\Domains\Licensing\Models\Propietario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Propietario>
 */
class PropietarioFactory extends Factory
{
    protected $model = Propietario::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
        ];
    }
}
