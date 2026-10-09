<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\PhoneLink;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Escanear el código de barras de un artículo con el teléfono, desde la
 * computadora (CLAUDE.md secc. 33): igual que el escaneo de documentos de
 * Conti (ContiScanService).
 *
 * - La computadora pide un QR con un pase de un solo uso, que vence en
 *   MINUTES y solo sirve para mandar un código a ese escaneo. No abre sesión
 *   en el teléfono ni deja ver nada.
 * - El teléfono lee el código ahí mismo (ZXing, en el navegador) y manda solo
 *   el texto: ninguna imagen sale del teléfono.
 * - La computadora va preguntando (status) y, cuando llega, lo pone en el
 *   campo: se guarda recién con el artículo, con las validaciones de siempre.
 */
class BarcodeScanService
{
    public const MINUTES = 10;

    private const PREFIX = 'barcode:scan:';

    /**
     * Empieza un escaneo: el pase para el QR. «para» es el artículo, para
     * que el teléfono diga qué se está escaneando.
     *
     * @return array{id: string, url: string, minutos: int}
     */
    public function start(User $user, int $companyId, ?string $for = null): array
    {
        $id = (string) Str::uuid();
        $token = Str::random(48);
        $expires = now()->addMinutes(self::MINUTES);

        Cache::put(self::PREFIX.$id, [
            'user_id' => $user->id,
            'company_id' => $companyId,
            'para' => filled($for) ? Str::limit(trim($for), 120) : null,
            'token' => hash('sha256', $token),
            'estado' => 'esperando',
            'codigo' => null,
            'vence' => $expires->getTimestamp(),
        ], $expires);
        Cache::put($this->tokenKey($token), $id, $expires);

        return ['id' => $id, 'url' => PhoneLink::url('barcode-phone.show', $token), 'minutos' => self::MINUTES];
    }

    /** El escaneo de un QR, si todavía espera el código: lo que muestra el teléfono. */
    public function forToken(string $token): ?array
    {
        $scan = $this->waiting($token);

        if ($scan === null) {
            return null;
        }

        $company = Company::find($scan['company_id']);

        return [
            'para' => $scan['para'],
            'compania' => $company?->trade_name ?: $company?->legal_name,
            'vence' => $scan['vence'],
        ];
    }

    /** El teléfono mandó el código: queda para la computadora, y el pase deja de servir. */
    public function deliver(string $token, string $code): void
    {
        $id = Cache::get($this->tokenKey($token)) ?? abort(410, 'Este código ya no sirve. Generá otro desde la computadora.');

        Cache::lock(self::PREFIX.'lock:'.$id, 10)->block(5, function () use ($id, $token, $code) {
            $scan = $this->waiting($token);

            if ($scan === null) {
                abort(410, 'Este código ya no sirve. Generá otro desde la computadora.');
            }

            Cache::put(self::PREFIX.$id, [...$scan, 'estado' => 'listo', 'codigo' => $code], max(1, $scan['vence'] - time()));
            Cache::forget($this->tokenKey($token));
        });
    }

    /**
     * En qué va un escaneo, para la computadora que lo empezó. El código se
     * entrega una sola vez.
     *
     * @return array{estado: string, codigo?: string}
     */
    public function status(User $user, int $companyId, string $id): array
    {
        $scan = Cache::get(self::PREFIX.$id);

        if (! is_array($scan) || $scan['user_id'] !== $user->id || $scan['company_id'] !== $companyId) {
            return ['estado' => 'vencido'];
        }

        if ($scan['estado'] !== 'listo') {
            return ['estado' => $scan['estado']];
        }

        Cache::forget(self::PREFIX.$id);

        return ['estado' => 'listo', 'codigo' => $scan['codigo']];
    }

    private function waiting(string $token): ?array
    {
        $id = Cache::get($this->tokenKey($token));
        $scan = $id ? Cache::get(self::PREFIX.$id) : null;

        if (! is_array($scan) || $scan['estado'] !== 'esperando' || ! hash_equals($scan['token'], hash('sha256', $token))) {
            return null;
        }

        return $scan;
    }

    private function tokenKey(string $token): string
    {
        return self::PREFIX.'token:'.hash('sha256', $token);
    }
}
