<?php

namespace Database\Factories\Domains\Banking\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'company_id' => $company->id,
            'gl_account_id' => ChartOfAccount::factory()->create(['company_id' => $company->id])->id,
            'bank_name' => fake()->company(),
            'account_number' => fake()->unique()->numerify('CR##################'),
            'currency_id' => $company->local_currency_id,
        ];
    }
}
