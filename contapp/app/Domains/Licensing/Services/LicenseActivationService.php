<?php

namespace App\Domains\Licensing\Services;

use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Models\AccountMaskConfig;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Licensing\Exceptions\AccountNotEligibleException;
use App\Domains\Licensing\Exceptions\InvalidLicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Canje de un código de licencia: bootstrap de UNA SOLA VEZ por licencia,
 * que crea la compañía y deja a su dueño (super usuario DE ESA LICENCIA, un
 * actor completamente distinto del Propietario — ver
 * App\Domains\Licensing\Models\Propietario) fijado como licenses.superuser_id.
 * No hay registro público: sin un código, acá no entra nadie.
 *
 * El dueño puede ser alguien nuevo (activate(): se le crea la cuenta) o una
 * cuenta que ya existe (activateForExistingUser()): quien ya es Administrador
 * o Usuario en la licencia de otra persona puede tener además la suya. Lo que
 * no puede es tener dos: una cuenta, una licencia.
 *
 * Compañías adicionales bajo una licencia ya activada se agregan desde
 * CompanyProvisioningService (el Superusuario ya logueado), nunca canjeando
 * el código de nuevo.
 */
class LicenseActivationService
{
    /**
     * Una persona nueva: se le crea la cuenta junto con la compañía.
     *
     * @param  array{legal_name:string, trade_name?:string, tax_id?:string}  $companyData
     * @param  array{name:string, email:string, password:string}  $userData
     * @return array{license: License, company: Company, user: User}
     *
     * @throws InvalidLicenseException
     * @throws AccountNotEligibleException
     */
    public function activate(string $code, array $companyData, array $userData, ?LicenseInvitation $invitation = null): array
    {
        $license = $this->redeemable($code, $invitation);

        // Después del código, no antes: así solo se entera de que un correo
        // ya tiene cuenta quien trae una licencia válida para canjear.
        if (User::where('email', $userData['email'])->exists()) {
            throw AccountNotEligibleException::emailTaken();
        }

        return DB::transaction(function () use ($license, $companyData, $userData) {
            $company = $this->createCompany($license, $companyData);

            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'default_company_id' => $company->id,
                'status' => 'active',
            ]);

            // Fuera de $fillable a propósito (ver User.php): se setea explícito.
            // La contraseña la eligió la persona en el formulario de activación.
            $user->forceFill(['is_super_admin' => true, 'password_chosen_at' => now()])->save();

            $company->users()->attach($user->id, ['is_default' => true]);

            // Fuera de $fillable a propósito (ver License.php): se fija acá,
            // de una sola vez, atómico con la creación de compañía+usuario.
            $license->forceFill(['superuser_id' => $user->id])->save();

            return ['license' => $license, 'company' => $company, 'user' => $user];
        });
    }

    /**
     * Una cuenta que ya existe pasa a ser dueña de su propia licencia, sin
     * dejar de ser lo que era en las de otros: sus membresías, sus roles y
     * sus permisos en otras compañías no se tocan, y tampoco su nombre, su
     * correo ni su contraseña.
     *
     * Quien llama tiene que haber comprobado ANTES que la cuenta es de quien
     * la está usando (sesión iniciada, o correo y contraseña verificados):
     * este método no autentica a nadie.
     *
     * No prende users.is_super_admin. Esa bandera solo se consulta en
     * compañías sin licencia (datos de seeder y de tests, ver
     * User::isSuperAdmin()), y prenderla le daría a esta cuenta, de rebote,
     * rango de Superusuario en cualquiera de esas a las que perteneciera. Con
     * licencia de por medio, lo que manda es licenses.superuser_id.
     *
     * @param  array{legal_name:string, trade_name?:string, tax_id?:string}  $companyData
     * @return array{license: License, company: Company, user: User}
     *
     * @throws InvalidLicenseException
     * @throws AccountNotEligibleException
     */
    public function activateForExistingUser(string $code, array $companyData, User $user, ?LicenseInvitation $invitation = null): array
    {
        $license = $this->redeemable($code, $invitation);

        $this->assertCanOwnLicense($user);

        return DB::transaction(function () use ($license, $companyData, $user) {
            $company = $this->createCompany($license, $companyData);

            // La compañía propia pasa a ser la predeterminada: es la que
            // acaba de contratar. Las demás siguen en el selector.
            DB::table('company_user')
                ->where('user_id', $user->id)
                ->update(['is_default' => false, 'updated_at' => now()]);

            $company->users()->attach($user->id, ['is_default' => true]);

            $user->forceFill(['default_company_id' => $company->id])->save();

            $license->forceFill(['superuser_id' => $user->id])->save();

            AuditLog::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'action' => 'license_activated',
                'auditable_type' => License::class,
                'auditable_id' => $license->id,
                'new_values' => ['company_id' => $company->id, 'existing_account' => true],
                'created_at' => now(),
            ]);

            return ['license' => $license, 'company' => $company, 'user' => $user];
        });
    }

    /**
     * La licencia de ese código, si todavía se puede canjear.
     *
     * Una licencia asignada desde el backoffice (LicenseInvitationService)
     * queda reservada para esa persona: solo se activa con su invitación, no
     * con el código.
     *
     * @throws InvalidLicenseException
     */
    public function redeemable(string $code, ?LicenseInvitation $invitation = null): License
    {
        $license = License::where('code', $code)->first();

        if (! $license) {
            throw new InvalidLicenseException('El código de licencia no existe.');
        }

        if ($license->superuser_id !== null) {
            throw new InvalidLicenseException('Esta licencia ya fue activada. Iniciá sesión con tu usuario para agregar otra compañía.');
        }

        $reservation = LicenseInvitation::where('license_id', $license->id)->whereNull('accepted_at')->first();

        if ($reservation !== null && $reservation->id !== $invitation?->id) {
            throw new InvalidLicenseException('Esta licencia está asignada a una persona y se activa desde el correo que recibió.');
        }

        if (! $license->canActivateAnotherCompany()) {
            throw new InvalidLicenseException(match (true) {
                $license->isRevoked() => 'Esta licencia fue revocada.',
                $license->isExpired() => 'Esta licencia está vencida.',
                default => 'Esta licencia ya alcanzó el máximo de compañías permitidas.',
            });
        }

        return $license;
    }

    /** La licencia de la que esta cuenta es dueña, si tiene una. */
    public function ownedLicense(User $user): ?License
    {
        return License::where('superuser_id', $user->id)->first();
    }

    /**
     * @throws AccountNotEligibleException
     */
    public function assertCanOwnLicense(User $user): void
    {
        if ($user->status !== 'active') {
            throw AccountNotEligibleException::inactive();
        }

        // Una cuenta, una licencia. Además de esta validación lo garantiza un
        // índice único en licenses.superuser_id.
        if ($this->ownedLicense($user) !== null) {
            throw AccountNotEligibleException::alreadyOwner();
        }

        // Una cuenta que dio de alta otra persona tiene la contraseña que esa
        // persona le puso. Con ella, el Superusuario de la OTRA licencia
        // podría entrar a la compañía que está por nacer. Elegirla por correo
        // (NewPasswordController) prueba que la casilla es de quien activa.
        if ($user->password_chosen_at === null) {
            throw AccountNotEligibleException::passwordNotChosen();
        }
    }

    /**
     * @param  array{legal_name:string, trade_name?:string, tax_id?:string}  $companyData
     */
    private function createCompany(License $license, array $companyData): Company
    {
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

        return $company;
    }
}
