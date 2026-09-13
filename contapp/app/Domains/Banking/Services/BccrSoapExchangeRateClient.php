<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Contracts\BccrExchangeRateClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente del Servicio Web de Indicadores Económicos del BCCR.
 *
 * IMPORTANTE — no probado contra el servicio real: este entorno de desarrollo
 * no tiene credenciales BCCR ni salida a internet verificada. La forma del
 * endpoint (GET con querystring devolviendo XML) y el indicador 317 para el
 * tipo de cambio de referencia de venta del USD siguen el patrón documentado
 * públicamente por el BCCR, pero deben confirmarse contra la documentación
 * oficial vigente (https://www.bccr.fi.cr, "Servicios Web") antes de
 * depender de esto en producción. SyncBccrExchangeRatesService y el resto
 * del dominio de bancos no dependen de que esta clase sea perfecta: si
 * fetchRate() devuelve null (por lo que sea), simplemente no se sincroniza
 * nada y los tipos de cambio siguen pudiendo cargarse manualmente.
 */
class BccrSoapExchangeRateClient implements BccrExchangeRateClient
{
    public function fetchRate(string $currencyCode, \DateTimeInterface $date): ?string
    {
        if ($currencyCode !== 'USD') {
            // El BCCR solo publica el par CRC/USD en este indicador; otras
            // monedas extranjeras requerirían otro indicador o fuente.
            return null;
        }

        $email = config('services.bccr.email');
        $token = config('services.bccr.token');

        if (! $email || ! $token) {
            Log::warning('BCCR: BCCR_EMAIL/BCCR_TOKEN no configurados; no se puede sincronizar tipo de cambio.');

            return null;
        }

        try {
            $response = Http::timeout(10)->get(config('services.bccr.endpoint'), [
                'Indicador' => config('services.bccr.indicator_usd_venta'),
                'FechaInicio' => $date->format('d/m/Y'),
                'FechaFinal' => $date->format('d/m/Y'),
                'Nombre' => $email,
                'SubNiveles' => 'N',
                'CorreoElectronico' => $email,
                'Token' => $token,
            ]);

            if (! $response->successful()) {
                Log::warning('BCCR: respuesta HTTP no exitosa al consultar tipo de cambio.', ['status' => $response->status()]);

                return null;
            }

            return $this->parseRateFromXml($response->body());
        } catch (\Throwable $e) {
            Log::warning('BCCR: excepción al consultar tipo de cambio.', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function parseRateFromXml(string $xml): ?string
    {
        try {
            $previous = libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml);
            libxml_use_internal_errors($previous);

            if ($doc === false) {
                return null;
            }

            $doc->registerXPathNamespace('ns', 'http://ws.sdde.bccr.fi.cr');
            $nodes = $doc->xpath('//NUM_VALOR') ?: $doc->xpath('//*[local-name()="NUM_VALOR"]');

            if (empty($nodes)) {
                return null;
            }

            $value = (string) $nodes[count($nodes) - 1];

            return is_numeric($value) ? number_format((float) $value, 6, '.', '') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
