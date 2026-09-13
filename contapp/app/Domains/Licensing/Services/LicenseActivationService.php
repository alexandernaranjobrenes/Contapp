<?php

namespace App\Domains\Licensing\Services;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Models\AccountMaskConfig;
use App\Domains\Core\Models\Company;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Canje de un código de licencia: bootstrap de UNA SOLA VEZ por licencia,
 * que crea la compañía y su primer usuario (super usuario DE ESA LICENCIA,
 * un actor completamente distinto del Propietario — ver
 * App\Domains\Licensing\Models\Propietario), y lo fija como
 * licenses.superuser_id. Es la única puerta de entrada para gente nueva: no
 * hay registro público. Compañías
 * adicionales bajo una licencia ya activada se agregan desde
 * CompanyProvisioningService (el Superusuario ya logueado), nunca canjeando
 * el código de nuevo.
 */
class LicenseActivationService
{
    /**
     * @param  array{legal_name:string, trade_name?:string, tax_id?:string}  $companyData
     * @param  array{name:string, email:string, password:string}  $userData
     * @return array{license: License, company: Company, user: User}
     */
    public function activate(string $code, array $companyData, array $userData): array
    {
        $license = License::where('code', $code)->first();

        if (! $license) {
            throw new InvalidLicenseException('El código de licencia no existe.');
        }

        if ($license->superuser_id !== null) {
            throw new InvalidLicenseException('Esta licencia ya fue activada. Iniciá sesión con tu usuario para agregar otra compañía.');
        }

        if (! $license->canActivateAnotherCompany()) {
            throw new InvalidLicenseException(match (true) {
                $license->isRevoked() => 'Esta licencia fue revocada.',
                $license->isExpired() => 'Esta licencia está vencida.',
                default => 'Esta licencia ya alcanzó el máximo de compañías permitidas.',
            });
        }

        return DB::transaction(function () use ($license, $companyData, $userData) {
            $crc = Currency::firstOrCreate(
                ['code' => 'CRC'],
                ['name' => 'Colón costarricense', 'symbol' => '₡', 'decimal_places' => 2]
            );
            $usd = Currency::firstOrCreate(
                ['code' => 'USD'],
                ['name' => 'Dólar estadounidense', 'symbol' => '$', 'decimal_places' => 2]
            );

            $company = Company::create([
                'license_id' => $license->id,
                'legal_name' => $companyData['legal_name'],
                'trade_name' => $companyData['trade_name'] ?? $companyData['legal_name'],
                'tax_id' => $companyData['tax_id'] ?? null,
                'country_code' => 'CR',
                'local_currency_id' => $crc->id,
                'foreign_currency_id' => $usd->id,
                'system_currency_id' => $usd->id,
                'timezone' => 'America/Costa_Rica',
                'status' => 'active',
            ]);

            AccountMaskConfig::create([
                'company_id' => $company->id,
                'segment_lengths' => [1, 2, 2, 2, 3],
            ]);

            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'default_company_id' => $company->id,
                'status' => 'active',
            ]);

            // Fuera de $fillable a propósito (ver User.php): se setea explícito.
            $user->forceFill(['is_super_admin' => true])->save();

            $company->users()->attach($user->id, ['is_default' => true]);

            // Fuera de $fillable a propósito (ver License.php): se fija acá,
            // de una sola vez, atómico con la creación de compañía+usuario.
            $license->forceFill(['superuser_id' => $user->id])->save();

            return ['license' => $license, 'company' => $company, 'user' => $user];
        });
    }
}
