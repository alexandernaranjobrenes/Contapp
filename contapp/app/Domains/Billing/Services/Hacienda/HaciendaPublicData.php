<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Support\CabysCatalog;
use App\Domains\Billing\Support\FiscalCatalogs;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Los datos públicos de Hacienda (api.hacienda.go.cr, sin credenciales y solo
 * de lectura): un código CAByS que no esté en el catálogo oficial que trae
 * CONTAPP (CabysCatalog), y las actividades económicas inscritas de una
 * cédula. Son los mismos que Hacienda usa para validar el comprobante:
 * un CAByS que no está en el catálogo se rechaza con -400, y una actividad
 * que el emisor no tiene inscrita, con -408 (docs/einvoice/rejections.md).
 *
 * Nada de esto puede impedir trabajar: si el API no contesta, cada consulta
 * devuelve null («no se sabe») y quien pregunta sigue como si no hubiera
 * preguntado. Solo «no existe», dicho por Hacienda, cuenta como no.
 *
 * Lo que se encuentra se guarda en caché (config hacienda.public_api):
 * el catálogo CAByS cambia pocas veces al año.
 */
class HaciendaPublicData
{
    /**
     * Un código CAByS: primero en el catálogo oficial que viene con CONTAPP
     * (CabysCatalog, sin red); si no está ahí, a Hacienda, por si es más nuevo.
     * ['exists' => false] si Hacienda dice que no está; null si no se pudo
     * preguntar.
     *
     * @return array{exists: bool, code?: string, description?: string, tax?: string|null, is_service?: bool}|null
     */
    public function cabys(string $code): ?array
    {
        if (! preg_match('/^\d{13}$/', $code)) {
            return ['exists' => false];
        }

        if (($local = CabysCatalog::find($code)) !== null) {
            return ['exists' => true, ...$local];
        }

        $key = "hacienda:cabys:code:{$code}";

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $data = $this->get('/fe/cabys', ['codigo' => $code]);

        if (! is_array($data)) {
            return null;
        }

        $match = collect($data)->first(fn ($row) => is_array($row) && ($row['codigo'] ?? null) === $code);
        $result = $match ? ['exists' => true, ...$this->entry($match)] : ['exists' => false];

        // Uno que no está se vuelve a preguntar antes: puede ser que Hacienda
        // lo agregue en la próxima versión del catálogo.
        Cache::put($key, $result, $match ? now()->addWeek() : now()->addHours(6));

        return $result;
    }

    /**
     * Regla de validación: el CAByS tiene que estar en el catálogo. Falla si
     * no está en el oficial y Hacienda tampoco lo tiene; si Hacienda no
     * contesta, el código pasa.
     */
    public function cabysRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_string($value) && preg_match('/^\d{13}$/', $value) && ($this->cabys($value)['exists'] ?? true) === false) {
                $fail("El código {$value} no está en el catálogo CAByS de Hacienda: un comprobante con él se rechaza (-400). Elegilo de la lista.");
            }
        };
    }

    /**
     * Lo que Hacienda tiene inscrito de una cédula: nombre, régimen, situación
     * y actividades económicas (con el código como lo pide la v4.4, «6209.0»).
     * ['found' => false] si Hacienda no la tiene; null si no se pudo preguntar.
     *
     * @return array<string, mixed>|null
     */
    public function taxpayer(string $identification): ?array
    {
        $identification = preg_replace('/\D/', '', $identification);

        if ($identification === '' || strlen($identification) > 20) {
            return ['found' => false];
        }

        $key = "hacienda:ae:{$identification}";

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $data = $this->get('/fe/ae', ['identificacion' => $identification], notFoundIsAnswer: true);

        if ($data === false) {
            $result = ['found' => false];
        } elseif (! is_array($data) || ! array_key_exists('actividades', $data)) {
            return null;
        } else {
            $result = [
                'found' => true,
                'name' => (string) ($data['nombre'] ?? ''),
                'identification_type' => (string) ($data['tipoIdentificacion'] ?? ''),
                'regime' => (string) ($data['regimen']['descripcion'] ?? ''),
                'status' => (string) ($data['situacion']['estado'] ?? ''),
                'activities' => collect($data['actividades'] ?? [])
                    ->filter(fn ($a) => is_array($a) && isset($a['codigo']))
                    ->map(fn (array $a) => [
                        'code' => (string) $a['codigo'],
                        'description' => trim((string) ($a['descripcion'] ?? '')),
                        // A = activa; P = principal, S = secundaria.
                        'active' => ($a['estado'] ?? 'A') === 'A',
                        'primary' => ($a['tipo'] ?? '') === 'P',
                    ])
                    ->values()
                    ->all(),
            ];
        }

        Cache::put($key, $result, now()->addHours(6));

        return $result;
    }

    /**
     * Si la actividad está inscrita y activa para esa cédula: true o false
     * según Hacienda; null si no se pudo preguntar.
     */
    public function activityIsRegistered(string $identification, string $activityCode): ?bool
    {
        $taxpayer = $this->taxpayer($identification);

        if ($taxpayer === null) {
            return null;
        }

        return collect($taxpayer['activities'] ?? [])
            ->contains(fn (array $a) => $a['active'] && $a['code'] === $activityCode);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{code: string, description: string, tax: string|null, is_service: bool}
     */
    private function entry(array $row): array
    {
        $code = (string) ($row['codigo'] ?? '');

        return [
            'code' => $code,
            'description' => trim((string) ($row['descripcion'] ?? '')),
            // La tarifa de IVA que el catálogo le asigna, en porcentaje.
            'tax' => isset($row['impuesto']) && is_numeric($row['impuesto']) ? (string) $row['impuesto'] : null,
            'is_service' => FiscalCatalogs::cabysIsService($code),
        ];
    }

    /**
     * El JSON de la respuesta; false si el API dijo 404 y eso es una
     * respuesta (la cédula no existe); null si no hubo respuesta útil.
     *
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query, bool $notFoundIsAnswer = false): mixed
    {
        if (! config('hacienda.public_api.enabled')) {
            return null;
        }

        try {
            /** @var Response $response */
            $response = Http::acceptJson()
                ->timeout((int) config('hacienda.public_api.timeout', 8))
                ->connectTimeout(5)
                ->get(rtrim((string) config('hacienda.public_api.url'), '/').$path, $query);
        } catch (ConnectionException $e) {
            Log::info('API público de Hacienda sin respuesta', ['path' => $path, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }

        if ($notFoundIsAnswer && $response->status() === 404) {
            return false;
        }

        if (! $response->successful()) {
            Log::info('API público de Hacienda respondió con error', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $response->json();
    }
}
