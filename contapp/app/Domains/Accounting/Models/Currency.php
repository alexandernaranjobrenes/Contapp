<?php

namespace App\Domains\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo maestro de monedas ISO 4217, global al tenant (no lleva company_id).
 * El rol de cada moneda (local/extranjera/sistema) lo define cada Company
 * a través de sus FK local_currency_id / foreign_currency_id / system_currency_id.
 */
class Currency extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'symbol', 'decimal_places'];
}
