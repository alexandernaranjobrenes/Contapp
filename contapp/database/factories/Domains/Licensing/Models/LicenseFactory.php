<?php

namespace Database\Factories\Domains\Licensing\Models;

use App\Domains\Licensing\Models\License;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LicenseFactory extends Factory
{
    protected $model = License::class;

    public function definition(): array
    {
        return [
            'code' => 'CONTAPP-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
            'max_companies' => 1,
            'max_admins' => 3,
            'max_users' => 10,
            'expires_at' => now()->addYear()->format('Y-m-d'),
            'status' => 'active',
            'notes' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()->format('Y-m-d')]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => 'revoked']);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
