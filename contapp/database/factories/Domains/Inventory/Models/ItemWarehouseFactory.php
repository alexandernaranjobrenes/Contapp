<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemWarehouseFactory extends Factory
{
    protected $model = ItemWarehouse::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_id' => Warehouse::factory(),
            'on_hand' => '0.000000',
        ];
    }
}
