<?php

namespace App\Domains\Conti\Models;

use App\Domains\Core\Models\Company;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que consumió un mensaje a Conti: tokens, costo en dólares y créditos.
 * Nunca el texto de la conversación. Sin el CompanyScope: el backoffice lo
 * suma por licencia, a través de todas sus compañías (ContiUsageService).
 */
class ContiUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'conti_usage';

    protected $fillable = [
        'license_id', 'company_id', 'user_id', 'model', 'prompt_tokens', 'cached_tokens',
        'completion_tokens', 'tool_calls', 'cost_usd', 'credits', 'status',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'credits' => 'decimal:4',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
