<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Inventory\Models\BillOfMaterial;
use App\Domains\Inventory\Models\BillOfMaterialLine;
use App\Domains\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillOfMaterialLineFactory extends Factory
{
    protected $model = BillOfMaterialLine::class;

    public function definition(): array
    {
        return [
            'bill_of_material_id' => BillOfMaterial::factory(),
            'component_item_id' => Item::factory(),
            'quantity' => 1,
            'scrap_percentage' => 0,
        ];
    }
}
