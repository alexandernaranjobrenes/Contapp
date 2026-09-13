<?php

namespace Database\Factories\Domains\Accounting\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->numerify('#-##-##-##-###'),
            'description_es' => fake()->words(3, true),
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'currency_mode' => 'local',
            'accepts_posting' => true,
            'is_financial_report' => true,
            'list_order' => 0,
            'tax_classification' => 'none',
            'is_active' => true,
        ];
    }

    public function nonPosting(): static
    {
        return $this->state(fn () => ['accepts_posting' => false]);
    }
}
