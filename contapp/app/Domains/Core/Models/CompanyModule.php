<?php

namespace App\Domains\Core\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyModule extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'module_id', 'enabled', 'enabled_at'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
