<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['company_id', 'name', 'type', 'can_grant_permissions'];

    protected function casts(): array
    {
        return [
            'can_grant_permissions' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }
}
