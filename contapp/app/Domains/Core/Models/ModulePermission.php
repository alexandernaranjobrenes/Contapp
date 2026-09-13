<?php

namespace App\Domains\Core\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ModulePermission extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'module_id', 'subject_type', 'subject_id', 'access_level'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
