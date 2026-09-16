<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\GlDetermination;
use Illuminate\Database\Eloquent\Factories\Factory;

class GlDeterminationFactory extends Factory
{
    protected $model = GlDetermination::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'scope_level' => 'company',
            'scope_id' => null,
            'category' => 'inventory',
            'account_id' => fn (array $attributes) => ChartOfAccount::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'cost_allocation_rule_id' => null,
        ];
    }
}
