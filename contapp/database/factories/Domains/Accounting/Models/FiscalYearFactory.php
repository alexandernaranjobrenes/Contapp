<?php

namespace Database\Factories\Domains\Accounting\Models;

use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'year' => now()->year,
            'status' => 'open',
        ];
    }
}
