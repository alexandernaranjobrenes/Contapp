<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Exceptions\HaciendaApiException;
use App\Domains\Billing\Models\HaciendaCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * El token del IDP de Hacienda (OpenID Connect) de una compañía en un
 * ambiente: vale unos 5 minutos y se renueva con el refresh_token, que vale
 * más. Se guarda cifrado en hacienda_credentials.
 *
 * Pedirlo está bajo un candado por credencial: diez comprobantes que salen a
 * la vez esperan al primero y usan su token, en vez de pedir diez (el IDP
 * corta a quien pide de más).
 */
class HaciendaTokenProvider
{
    /** Margen antes del vencimiento: un token que vence en 20 s ya no sirve para un envío lento. */
    private const MARGIN_SECONDS = 30;

    public function token(HaciendaCredential $credential, bool $forceNew = false): string
    {
        if (! $credential->hasApiCredentials()) {
            throw new HaciendaApiException(
                'Faltan el usuario o la contraseña del API del ATV para '.$this->label($credential).'.'
            );
        }

        return Cache::lock('hacienda:token:'.$credential->id, 30)->block(25, function () use ($credential, $forceNew) {
            $credential->refresh();

            if (! $forceNew && filled($credential->getRawOriginal('access_token'))
                && $credential->token_expires_at?->isAfter(now()->addSeconds(self::MARGIN_SECONDS))) {
                return (string) $credential->access_token;
            }

            if (filled($credential->getRawOriginal('refresh_token'))
                && $credential->refresh_expires_at?->isAfter(now()->addSeconds(self::MARGIN_SECONDS))) {
                try {
                    return $this->store($credential, $this->grant($credential, [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => (string) $credential->refresh_token,
                    ]));
                } catch (HaciendaApiException $e) {
                    if ($e->transient) {
                        throw $e;
                    }
                    // Un refresh vencido o revocado: se pide uno nuevo con la contraseña.
                }
            }

            return $this->store($credential, $this->grant($credential, [
                'grant_type' => 'password',
                'username' => (string) $credential->username,
                'password' => (string) $credential->password,
            ]));
        });
    }

    /** Un token que Hacienda rechazó (401): el próximo envío pide otro. */
    public function forget(HaciendaCredential $credential): void
    {
        $credential->forceFill([
            'access_token' => null,
            'token_expires_at' => null,
        ])->save();
    }

    /**
     * @param  array<string, string>  $grant
     * @return array<string, mixed>
     */
    private function grant(HaciendaCredential $credential, array $grant): array
    {
        $environment = config("hacienda.environments.{$credential->environment}")
            ?? throw new HaciendaApiException("Ambiente de Hacienda desconocido: {$credential->environment}.");

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('hacienda.timeout'))
                ->connectTimeout((int) config('hacienda.connect_timeout'))
                ->post($environment['idp_url'], [...$grant, 'client_id' => $environment['client_id']]);
        } catch (ConnectionException $e) {
            throw new HaciendaApiException('No se pudo conectar con el servicio de autenticación de Hacienda.', transient: true, previous: $e);
        }

        if ($response->successful() && is_string($response->json('access_token'))) {
            return $response->json();
        }

        $error = (string) ($response->json('error') ?? '');
        $description = (string) ($response->json('error_description') ?? '');

        $message = match (true) {
            $error === 'invalid_grant' && $grant['grant_type'] === 'password' => 'Hacienda rechazó el usuario o la contraseña del API del ATV ('.$this->label($credential).').',
            $error === 'invalid_grant' => 'La sesión con Hacienda venció.',
            $response->serverError() => 'El servicio de autenticación de Hacienda no está respondiendo. Probá de nuevo en unos minutos.',
            default => 'Hacienda no aceptó la conexión'.($description !== '' ? ": {$description}" : ($error !== '' ? ": {$error}" : '.')),
        };

        throw new HaciendaApiException(
            $message,
            status: $response->status(),
            errorCause: $description !== '' ? $description : ($error !== '' ? $error : null),
            transient: $response->serverError(),
        );
    }

    /** @param  array<string, mixed>  $token */
    private function store(HaciendaCredential $credential, array $token): string
    {
        $credential->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 300)),
            'refresh_expires_at' => isset($token['refresh_token'])
                ? now()->addSeconds((int) ($token['refresh_expires_in'] ?? 1800))
                : null,
        ])->save();

        return (string) $token['access_token'];
    }

    private function label(HaciendaCredential $credential): string
    {
        return config("hacienda.environments.{$credential->environment}.label", $credential->environment);
    }
}
