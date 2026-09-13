<?php

namespace Database\Factories\Domains\Core\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\DocumentTypeNumberSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentTypeNumberSeriesFactory extends Factory
{
    protected $model = DocumentTypeNumberSeries::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_type_id' => DocumentType::factory(),
            'name' => 'Serie '.fake()->unique()->randomLetter(),
            'range_from' => 1,
            'range_to' => 1000,
            'next_number' => 1,
            'is_active' => true,
        ];
    }
}
