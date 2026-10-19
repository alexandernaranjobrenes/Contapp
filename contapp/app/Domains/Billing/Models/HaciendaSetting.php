<?php

namespace App\Domains\Billing\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * La facturación electrónica de una compañía: el ambiente, si está encendida
 * y los datos del emisor que el XML exige. La carga solo el Superusuario
 * (HaciendaSettingsController). Las credenciales van aparte, por ambiente,
 * en HaciendaCredential.
 */
class HaciendaSetting extends Model
{
    use BelongsToCompany, HasFactory;

    public const ENVIRONMENTS = ['stag', 'prod'];

    protected $fillable = [
        'company_id', 'environment', 'enabled', 'enabled_at', 'enabled_by',
        'identification_type', 'province', 'canton', 'district', 'neighborhood', 'address_details',
        'phone', 'emails', 'branch', 'terminal', 'send_to_customer', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'emails' => 'array',
            'send_to_customer' => 'boolean',
        ];
    }

    public function isProduction(): bool
    {
        return $this->environment === 'prod';
    }

    /** @return list<string> */
    public function emailList(): array
    {
        return array_values(array_filter(array_map('trim', (array) $this->emails)));
    }
}
