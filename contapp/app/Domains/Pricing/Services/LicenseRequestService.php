<?php

namespace App\Domains\Pricing\Services;

use App\Domains\Licensing\Models\Propietario;
use App\Domains\Pricing\Models\LicenseRequest;
use App\Domains\Pricing\Models\LicenseRequestReply;
use App\Domains\Pricing\Models\PricingPackage;
use App\Domains\Pricing\Models\PricingSetting;
use App\Domains\Pricing\Support\PricingCalculator;
use App\Domains\Pricing\Support\PricingCatalog;
use App\Notifications\LicenseRequestReceivedNotification;
use App\Notifications\LicenseRequestReplyNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * Las solicitudes de licencia (CLAUDE.md secc. 42): la que llega desde
 * /precios y lo que el backoffice decide con ella —responderla por correo o
 * descartarla—.
 *
 * El correo se manda en el momento, sin cola (como los avisos del canal de
 * comentarios): si falla, la solicitud igual queda guardada, y una
 * respuesta que no salió queda anotada como fallida, sin marcar la
 * solicitud como respondida.
 */
class LicenseRequestService
{
    /**
     * Guarda una solicitud con la cotización que calcula el servidor (lo que
     * mande el navegador como precio no cuenta) y le avisa al backoffice.
     *
     * @param  array{contact_name: string, company_name: string, identification?: ?string, email: string, phone?: ?string, message?: ?string, package?: ?string, cycle: string, currency: string, selection: array<string, int>}  $data
     */
    public function create(array $data, ?string $ip, ?string $userAgent): LicenseRequest
    {
        $settings = PricingSetting::current();
        $catalog = PricingCatalog::forSite($settings);

        $quote = PricingCalculator::quote($catalog, $data['package'] ?? null, $data['selection'], $data['cycle'], $data['currency']);

        if ($quote['lines'] === []) {
            throw new RuntimeException('Elegí al menos una función para tu plan.');
        }

        $package = $quote['package'] ? PricingPackage::query()->where('slug', $quote['package']['slug'])->first() : null;

        $request = LicenseRequest::create([
            'status' => LicenseRequest::STATUS_NEW,
            'contact_name' => trim($data['contact_name']),
            'company_name' => trim($data['company_name']),
            'identification' => self::nullable($data['identification'] ?? null),
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => self::nullable($data['phone'] ?? null),
            'message' => self::nullable($data['message'] ?? null),
            'package_id' => $package?->id,
            // Con el paquete entero es ese paquete; con algo quitado, ya es otro plan.
            'package_name' => $package ? ($quote['intact'] ? $package->name : $package->name.' (personalizado)') : null,
            'billing_cycle' => $quote['cycle'],
            'currency' => $quote['currency'],
            'quote' => $quote,
            'total' => $quote['total'] / 100,
            'consented_at' => now(),
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
        ]);

        $this->notifyBackoffice($request, $settings);

        return $request;
    }

    /**
     * Le responde por correo. Si el correo no sale, queda anotado como
     * fallido y la solicitud no pasa a respondida.
     *
     * @return bool si el correo salió
     */
    public function reply(LicenseRequest $request, Propietario $by, string $subject, string $body, bool $includeQuote): bool
    {
        $error = null;

        try {
            Notification::route('mail', $request->email)
                ->notify(new LicenseRequestReplyNotification($request, $subject, $body, $includeQuote));
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        DB::transaction(function () use ($request, $by, $subject, $body, $includeQuote, $error) {
            LicenseRequestReply::create([
                'license_request_id' => $request->id,
                'propietario_id' => $by->id,
                'to_email' => $request->email,
                'subject' => $subject,
                'body' => $body,
                'include_quote' => $includeQuote,
                'status' => $error === null ? 'sent' : 'failed',
                'error' => $error === null ? null : mb_substr($error, 0, 1000),
            ]);

            if ($error === null) {
                $request->update([
                    'status' => LicenseRequest::STATUS_REPLIED,
                    'handled_by' => $by->id,
                    'handled_at' => now(),
                ]);
            }
        });

        return $error === null;
    }

    /** La da por atendida sin responderle, con una nota interna opcional. */
    public function discard(LicenseRequest $request, Propietario $by, ?string $note): void
    {
        $request->update([
            'status' => LicenseRequest::STATUS_DISCARDED,
            'internal_note' => self::nullable($note) ?? $request->internal_note,
            'handled_by' => $by->id,
            'handled_at' => now(),
        ]);
    }

    public function reopen(LicenseRequest $request): void
    {
        $request->update(['status' => LicenseRequest::STATUS_NEW, 'handled_by' => null, 'handled_at' => null]);
    }

    /** El aviso al backoffice: si no sale, la solicitud igual quedó guardada. */
    private function notifyBackoffice(LicenseRequest $request, PricingSetting $settings): void
    {
        $to = $settings->notification_email ?: config('site.requests_email');

        if (! $to) {
            return;
        }

        try {
            Notification::route('mail', $to)->notify(new LicenseRequestReceivedNotification($request));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
