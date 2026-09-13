<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostAllocationRule extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'valid_from', 'valid_until', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CostAllocationRuleLine::class)->orderBy('position');
    }

    /**
     * Mismo criterio de vigencia que CostCenter::isPostableOn(), aplicado a
     * la norma en sí — además de que cada centro de costo que reparte
     * también debe estar vigente (se valida aparte, por línea, en
     * PostJournalService::post()).
     */
    public function isEffectiveOn(\DateTimeInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from->format('Y-m-d') > $date->format('Y-m-d')) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->format('Y-m-d') < $date->format('Y-m-d')) {
            return false;
        }

        return true;
    }
}
