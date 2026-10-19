<?php

namespace App\Domains\Billing\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Último número fiscal usado por sucursal, terminal y tipo de comprobante.
 * Lo asigna FiscalSequenceAllocator, con la fila bloqueada.
 */
class FiscalSequence extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'branch', 'terminal', 'document_type', 'last_number'];

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}
