<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class PriceListItemFactory extends Factory
{
    protected $model = PriceListItem::class;

    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'item_id' => Item::factory(),
            'unit_price' => fake()->randomFloat(2, 100, 10000),
        ];
    }
}
