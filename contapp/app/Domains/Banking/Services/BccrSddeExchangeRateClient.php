<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Contracts\BccrExchangeRateClient;
use App\Domains\Banking\Exceptions\BccrUnavailableException;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * El tipo de cambio del dólar, desde la API de indicadores económicos del
 * BCCR: el Sistema de Divulgación de Datos Económicos (SDDE). Reemplazó en
 * 2025 al servicio web anterior (wsindicadoreseconomicos.asmx), que ya no
 * responde.
 *
 * - Documentación oficial: «Estándar electrónico para usar el nuevo Sistema
 *   de Divulgación de Datos Económicos (SDDE)», BCCR, vigente desde el 7 de
 *   abril de 2025.
 * - GET {base}/indicadoresEconomicos/{indicador}/series?fechaInicio=AAAA/MM/DD
 *   &fechaFin=AAAA/MM/DD&idioma=ES, con «Authorization: Bearer {token}».
 *   Responde { estado, mensaje, datos: [{ series: [{ fecha, valorDatoPorPeriodo }] }] }.
 * - El token se genera en el sitio de Indicadores Económicos del BCCR, en
 *   Mi perfil → Generar token, y va en BCCR_TOKEN. Puede vencer: el BCCR
 *   responde 401 y se genera otro.
 * - Indicador 318: el tipo de cambio de referencia de VENTA (el 317 es el de
 *   compra). Es el que usa Hacienda para los comprobantes en dólares.
 */
class BccrSddeExchangeRateClient implements BccrExchangeRateClient
{
    /** @var array<string, ?string> Lo ya consultado: sincronizar todas las compañías pregunta una vez por fecha. */
    private array $fetched = [];

    public function fetchRate(string $currencyCode, DateTimeInterface $date): ?string
    {
        // El BCCR publica el tipo de cambio colón/dólar; de otra moneda, no.
        if ($currencyCode !== 'USD') {
            return null;
        }

        $day = $date->format('Y-m-d');

        if (! array_key_exists($day, $this->fetched)) {
            $this->fetched[$day] = $this->request($date);
        }

        return $this->fetched[$day];
    }

    private function request(DateTimeInterface $date): ?string
    {
        $token = trim((string) config('services.bccr.token'));

        if ($token === '') {
            throw new BccrUnavailableException(
                'Falta el token del BCCR (BCCR_TOKEN). Se genera en el sitio de Indicadores Económicos del BCCR, en Mi perfil → Generar token.'
            );
        }

        $indicator = config('services.bccr.indicator');
        $day = $date->format('Y/m/d');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withUserAgent('CONTAPP')
                ->timeout(15)
                ->retry(2, 500, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->get(rtrim((string) config('services.bccr.base_url'), '/')."/indicadoresEconomicos/{$indicator}/series", [
                    'fechaInicio' => $day,
                    'fechaFin' => $day,
                    'idioma' => 'ES',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('BCCR: sin conexión al consultar el tipo de cambio.', ['message' => $e->getMessage()]);

            throw new BccrUnavailableException('No se pudo conectar con el BCCR. Probá de nuevo en unos minutos.', previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('BCCR: respuesta no exitosa al consultar el tipo de cambio.', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
            ]);

            throw new BccrUnavailableException(match (true) {
                $response->status() === 401 => 'El BCCR rechazó el token (BCCR_TOKEN): venció o no es válido. Generá uno nuevo en el sitio de Indicadores Económicos del BCCR, en Mi perfil → Generar token.',
                $response->status() === 403 => 'El BCCR dice que la suscripción de ese token no está activa. Revisala en el sitio de Indicadores Económicos del BCCR.',
                $response->status() === 429 => 'El BCCR está recibiendo demasiadas consultas. Probá de nuevo en unos minutos.',
                // Los tokens del BCCR son JWT («eyJ…»: tres partes separadas
                // por puntos). Uno con otra forma —copiado a medias— el BCCR
                // no lo rechaza con 401: responde 500.
                ! self::looksLikeToken($token) => 'El token del BCCR (BCCR_TOKEN) no tiene la forma de los que genera el BCCR: copialo completo desde Mi perfil → Generar token.',
                default => "El BCCR no respondió bien (código {$response->status()}). Probá de nuevo en unos minutos.",
            });
        }

        $series = $response->json('datos.0.series');

        if ($response->json('estado') === false || ! is_array($series)) {
            Log::warning('BCCR: respuesta sin la serie del tipo de cambio.', ['body' => Str::limit($response->body(), 300)]);

            $message = trim((string) $response->json('mensaje'));

            throw new BccrUnavailableException($message !== ''
                ? "El BCCR respondió: «{$message}»."
                : 'El BCCR respondió algo que no se entiende. Probá de nuevo en unos minutos.');
        }

        // La fecha pedida, con su valor. Si todavía no está publicada, viene
        // vacía o con el valor en null.
        foreach ($series as $point) {
            $value = $point['valorDatoPorPeriodo'] ?? null;

            if (substr((string) ($point['fecha'] ?? ''), 0, 10) === $date->format('Y-m-d') && is_numeric($value) && (float) $value > 0) {
                return number_format((float) $value, 6, '.', '');
            }
        }

        return null;
    }

    private static function looksLikeToken(string $token): bool
    {
        return preg_match('/^[\w-]+\.[\w-]+\.[\w-]+$/', trim($token)) === 1;
    }
}
