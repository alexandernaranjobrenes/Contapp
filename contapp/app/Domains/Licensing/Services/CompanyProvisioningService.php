<?php

namespace App\Domains\Licensing\Services;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Models\AccountMaskConfig;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Exceptions\NotLicenseSuperuserException;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Compañías adicionales bajo una licencia YA activada (a diferencia de
 * LicenseActivationService::activate(), que es el bootstrap de una sola vez):
 * nunca crea un usuario nuevo, siempre adjunta la compañía nueva al mismo
 * Superusuario dueño de la licencia (CLAUDE.md secc. 12), consumiendo cupo
 * de max_companies.
 */
class CompanyProvisioningService
{
    /**
     * @param  array{legal_name:string, trade_name?:string, tax_id?:string}  $companyData
     *
     * @throws NotLicenseSuperuserException
     * @throws InvalidLicenseException
     */
    public function createAdditionalCompany(User $superuser, License $license, array $companyData): Company
    {
        if ($superuser->id !== $license->superuser_id) {
            throw new NotLicenseSuperuserException('Solo el superusuario dueño de esta licencia puede agregar compañías.');
        }

        if (! $license->canActivateAnotherCompany()) {
            throw new InvalidLicenseException(match (true) {
                $license->isBlocked() => 'Esta licencia fue suspendida o revocada.',
                $license->isExpired() => 'Esta licencia está vencida.',
                default => 'Esta licencia ya alcanzó el máximo de compañías permitidas.',
            });
        }

        return DB::transaction(function () use ($superuser, $license, $companyData) {
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

            // is_default=false a propósito: el usuario sigue en la compañía
            // que tenía activa, y cambia de compañía con el selector ya
            // existente (CompanySwitchController) si quiere operar la nueva.
            $superuser->companies()->attach($company->id, ['is_default' => false]);

            AuditLog::create([
                'company_id' => $company->id,
                'user_id' => $superuser->id,
                'action' => 'company_created',
                'auditable_type' => Company::class,
                'auditable_id' => $company->id,
                'new_values' => ['legal_name' => $company->legal_name, 'license_id' => $license->id],
                'created_at' => now(),
            ]);

            return $company;
        });
    }
}
