<?php

namespace Database\Factories\Domains\BusinessPartners\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessPartnerFactory extends Factory
{
    protected $model = BusinessPartner::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'company_id' => $company->id,
            'code' => 'C-'.fake()->unique()->numerify('###'),
            'name' => fake()->company(),
            'type' => 'client',
            'gl_account_id' => ChartOfAccount::factory()->create(['company_id' => $company->id])->id,
            'currency_id' => $company->local_currency_id,
            'status' => 'active',
        ];
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['code' => 'P-'.fake()->unique()->numerify('###'), 'type' => 'supplier']);
    }
}
