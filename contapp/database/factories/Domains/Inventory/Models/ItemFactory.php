<?php

namespace Database\Factories\Domains\Inventory\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->bothify('ART-####')),
            'name' => fake()->words(3, true),
            'item_group_id' => null,
            // La unidad de medida nace en la MISMA compañía que el artículo:
            // un uom_id de otra compañía pasaría la FK pero sería invisible
            // para el CompanyScope, y el test fallaría por una razón que no
            // es la que se está probando.
            'uom_id' => fn (array $attributes) => UnitOfMeasure::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'barcode' => null,
            'is_inventory_item' => true,
            'is_sales_item' => true,
            'is_purchase_item' => true,
            'tax_rate_id' => null,
            // Un artículo nuevo no tiene costo: lo fija la primera entrada.
            'avg_cost_local' => '0.000000',
            'avg_cost_foreign' => '0.000000',
            'status' => 'active',
        ];
    }

    public function service(): static
    {
        return $this->state(['is_inventory_item' => false]);
    }
}
