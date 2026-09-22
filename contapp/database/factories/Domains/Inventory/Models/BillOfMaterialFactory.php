<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\BillOfMaterial;
use App\Domains\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillOfMaterialFactory extends Factory
{
    protected $model = BillOfMaterial::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'item_id' => Item::factory(),
            'code' => strtoupper(fake()->unique()->lexify('BOM-???')),
            'name' => fake()->words(2, true),
            'output_quantity' => 1,
            'is_default' => false,
            'status' => 'active',
        ];
    }
}
