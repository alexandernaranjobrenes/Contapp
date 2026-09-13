<?php

namespace Database\Factories\Domains\Core\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(3, true),
            'origin_module' => 'contable',
            'generates_journal' => true,
            'numbering_mask' => '99999999',
            'field_count' => 8,
            'next_consecutive' => 1,
            'consecutive_on_save' => true,
            'currency_mode' => 'libre',
            'status' => 'active',
        ];
    }
}
