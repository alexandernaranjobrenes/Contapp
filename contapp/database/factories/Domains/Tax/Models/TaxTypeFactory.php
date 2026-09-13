<?php

namespace Database\Factories\Domains\Tax\Models;

use App\Domains\Tax\Models\TaxType;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaxTypeFactory extends Factory
{
    protected $model = TaxType::class;

    public function definition(): array
    {
        return [
            'code' => 'IVA',
            'name' => 'Impuesto al Valor Agregado',
        ];
    }
}
