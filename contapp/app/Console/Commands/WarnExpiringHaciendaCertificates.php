<?php

namespace App\Console\Commands;

use App\Domains\Billing\Models\HaciendaCredential;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Mail\HaciendaCertificateExpiringMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa al Superusuario de cada compañía que su llave criptográfica de
 * Hacienda vence pronto (hacienda.certificate_warning_days: 30, 15, 7 y 1
 * día). Cada aviso va una vez: certificate_warned_days guarda el último, y una
 * llave nueva lo vuelve a poner en cero.
 */
class WarnExpiringHaciendaCertificates extends Command
{
    protected $signature = 'contapp:hacienda-certificates';

    protected $description = 'Avisa a los Superusuarios de las llaves de Hacienda por vencer';

    public function handle(): int
    {
        $thresholds = collect((array) config('hacienda.certificate_warning_days'))->sortDesc()->values();
        $horizon = now()->addDays((int) $thresholds->first() + 1);
        $sent = 0;

        $credentials = HaciendaCredential::withoutGlobalScope(CompanyScope::class)
            ->whereNotNull('certificate_valid_to')
            ->where('certificate_valid_to', '>', now())
            ->where('certificate_valid_to', '<=', $horizon)
            ->get();

        foreach ($credentials as $credential) {
            $days = (int) ceil(now()->diffInHours($credential->certificate_valid_to) / 24);
            $threshold = $thresholds->filter(fn (int $t) => $days <= $t)->last();

            if ($threshold === null || ($credential->certificate_warned_days !== null && $credential->certificate_warned_days <= $threshold)) {
                continue;
            }

            $company = Company::with('license.superuser')->find($credential->company_id);
            $superuser = $company?->license?->superuser;

            if (! $superuser?->email) {
                continue;
            }

            try {
                Mail::to($superuser->email)->send(new HaciendaCertificateExpiringMail(
                    companyName: $company->trade_name ?: $company->legal_name,
                    environmentLabel: config("hacienda.environments.{$credential->environment}.label", $credential->environment),
                    expiresOn: $credential->certificate_valid_to->setTimezone(config('hacienda.timezone'))->format('d/m/Y'),
                    days: max(1, $days),
                    recipientName: $superuser->name,
                ));
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $credential->forceFill(['certificate_warned_days' => $threshold])->save();
            $sent++;
        }

        $this->info("Avisos enviados: {$sent}");

        return self::SUCCESS;
    }
}
