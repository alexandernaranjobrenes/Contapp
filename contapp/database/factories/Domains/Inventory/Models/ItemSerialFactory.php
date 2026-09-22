<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemSerial;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemSerialFactory extends Factory
{
    protected $model = ItemSerial::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'serial_number' => strtoupper(fake()->unique()->bothify('SN-####-????')),
            'status' => 'in_stock',
        ];
    }
}
