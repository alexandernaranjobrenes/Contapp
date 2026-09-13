<?php

namespace App\Domains\Core\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AccountMaskConfig extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'segment_lengths'];

    protected function casts(): array
    {
        return [
            'segment_lengths' => 'array',
        ];
    }
}
