<?php

namespace Database\Factories\Domains\Accounting\Models;

use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

class FiscalPeriodFactory extends Factory
{
    protected $model = FiscalPeriod::class;

    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'fiscal_year_id' => FiscalYear::factory(),
            'period_number' => (int) $start->format('n'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $start->copy()->endOfMonth()->format('Y-m-d'),
            'status' => 'open',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'closed']);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['status' => 'blocked']);
    }
}
