<?php

namespace Database\Factories\Domains\Tax\Models;

use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxType;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    public function definition(): array
    {
        return [
            'tax_type_id' => TaxType::factory(),
            'code' => 'IVA-13',
            'name' => 'IVA tarifa general 13%',
            'percentage' => '13.00',
            'effective_from' => '2019-07-01',
            'effective_to' => null,
        ];
    }
}
