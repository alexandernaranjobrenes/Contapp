<?php

namespace App\Domains\Core\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Los elementos del Panel de una compañía, en orden (DashboardService).
 * Una sola fila por compañía.
 */
class DashboardLayout extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'widgets', 'updated_by'];

    protected function casts(): array
    {
        return [
            'widgets' => 'array',
        ];
    }
}
