<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ProductionOrder;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionOrderFactory extends Factory
{
    protected $model = ProductionOrder::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'item_id' => fn (array $attributes) => Item::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'warehouse_id' => fn (array $attributes) => Warehouse::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'planned_quantity' => '10.000000',
            'produced_quantity' => '0.000000',
            'order_date' => now()->format('Y-m-d'),
            'status' => 'open',
        ];
    }
}
