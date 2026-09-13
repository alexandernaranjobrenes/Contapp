<?php

namespace Database\Factories\Domains\Accounting\Models;

use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CostAllocationRuleFactory extends Factory
{
    protected $model = CostAllocationRule::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->bothify('NORMA-##'),
            'name' => fake()->words(2, true),
            'valid_from' => now()->subYear()->format('Y-m-d'),
            'valid_until' => null,
            'is_active' => true,
        ];
    }

    /**
     * Reparte 100% entre los centros de costo dados, a partes iguales (el
     * remanente de redondeo cae en el último) — conveniencia para tests que
     * no necesitan porcentajes específicos, solo que la norma sea válida
     * (sume exactamente 100%).
     */
    public function withEvenSplit(CostCenter ...$costCenters): static
    {
        return $this->afterCreating(function (CostAllocationRule $rule) use ($costCenters) {
            $count = count($costCenters);
            $base = bcdiv('100', (string) $count, 2);
            $running = '0.00';

            foreach (array_values($costCenters) as $index => $costCenter) {
                $isLast = $index === $count - 1;
                $percentage = $isLast ? bcsub('100.00', $running, 2) : $base;
                $running = bcadd($running, $percentage, 2);

                $rule->lines()->create([
                    'cost_center_id' => $costCenter->id,
                    'percentage' => $percentage,
                    'position' => $index + 1,
                ]);
            }
        });
    }
}
