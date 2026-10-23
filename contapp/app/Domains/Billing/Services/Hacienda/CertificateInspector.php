<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Exceptions\CertificateException;
use App\Domains\Billing\Models\HaciendaCredential;
use Illuminate\Support\Facades\Process;

/**
 * Abre y revisa la llave criptográfica (.p12) que el ATV entrega a cada
 * contribuyente.
 *
 * Todo lo que puede hacer fallar una firma se revisa AL CARGARLA, no al
 * facturar: el PIN, que el archivo traiga llave y certificado y que sean
 * pareja, la vigencia, que sea RSA, y que el titular sea la compañía. Así el
 * error lo ve el Superusuario en su pantalla, y no una cajera a mitad de una
 * venta.
 *
 * ── Llaves con algoritmos viejos ─────────────────────────────────────────
 *
 * Muchas llaves del ATV vienen cifradas con RC2-40, que OpenSSL 3 ya no abre
 * sin el proveedor «legacy» («digital envelope routines::unsupported»). En
 * ese caso se abren con el comando `openssl pkcs12 -legacy` y se vuelven a
 * cifrar con los algoritmos actuales de OpenSSL, con el mismo PIN. Lo que se
 * guarda es esa versión: firmar después ya no depende del proveedor legacy.
 * La llave descifrada pasa solo por la tubería del proceso, nunca por el
 * disco (el archivo temporal es el .p12 todavía cifrado, y se borra enseguida).
 */
class CertificateInspector
{
    /**
     * @return array{p12: string, metadata: array<string, mixed>, warnings: list<string>}
     */
    public function inspect(string $p12, string $pin, ?string $expectedHolderId = null, ?\DateTimeInterface $at = null): array
    {
        $at ??= now();
        [$certificatePem, $privateKeyPem, $normalized] = $this->open($p12, $pin);

        $parsed = openssl_x509_parse($certificatePem);

        if ($parsed === false) {
            throw new CertificateException('El certificado de la llave no se pudo leer.');
        }

        $key = openssl_pkey_get_private($privateKeyPem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new CertificateException('La llave no es RSA: Hacienda firma con RSA-SHA256.');
        }

        if (! openssl_x509_check_private_key($certificatePem, $key)) {
            throw new CertificateException('La llave privada no corresponde al certificado del archivo.');
        }

        $validFrom = (new \DateTimeImmutable)->setTimestamp((int) $parsed['validFrom_time_t']);
        $validTo = (new \DateTimeImmutable)->setTimestamp((int) $parsed['validTo_time_t']);

        if ($validTo <= $at) {
            throw new CertificateException(
                'La llave venció el '.$validTo->setTimezone(new \DateTimeZone(config('hacienda.timezone')))->format('d/m/Y').
                '. Generá una nueva en el ATV.'
            );
        }

        if ($validFrom > $at) {
            throw new CertificateException('La llave todavía no es válida: empieza a regir el '.$validFrom->format('d/m/Y').'.');
        }

        $holderId = $this->holderId($parsed['subject'] ?? []);
        $warnings = [];

        if ($expectedHolderId !== null && $expectedHolderId !== '') {
            $expected = ltrim(preg_replace('/\D/', '', $expectedHolderId), '0');

            if ($holderId !== null && ltrim($holderId, '0') !== $expected) {
                throw new CertificateException(
                    "La llave es del contribuyente {$holderId}, no de la compañía ({$expectedHolderId}). ".
                    'Cada compañía firma con su propia llave.'
                );
            }

            if ($holderId === null) {
                $warnings[] = 'No se pudo leer la cédula del titular en el certificado: confirmá que la llave sea de esta compañía.';
            }
        }

        return [
            'p12' => $normalized ?? $p12,
            'metadata' => [
                'certificate_subject' => mb_substr($this->distinguishedName($parsed['subject'] ?? []), 0, 255),
                'certificate_issuer' => mb_substr($this->distinguishedName($parsed['issuer'] ?? []), 0, 255),
                'certificate_serial' => $this->serialDecimal($parsed),
                'certificate_holder_id' => $holderId,
                'certificate_valid_from' => $validFrom,
                'certificate_valid_to' => $validTo,
                'certificate_fingerprint' => openssl_x509_fingerprint($certificatePem, 'sha256') ?: null,
            ],
            'warnings' => $warnings,
        ];
    }

    /** La llave lista para firmar, descifrada de las credenciales guardadas. */
    public function signingKey(HaciendaCredential $credential): SigningKey
    {
        if (! $credential->hasCertificate()) {
            throw new CertificateException('No hay llave criptográfica cargada para '.$credential->environment.'.');
        }

        if ($credential->certificateExpired()) {
            throw new CertificateException(
                'La llave criptográfica venció el '.$credential->certificate_valid_to->format('d/m/Y').
                '. El Superusuario tiene que cargar una nueva en Administración → Facturación electrónica.'
            );
        }

        [$certificatePem, $privateKeyPem] = $this->open($credential->certificateBytes(), (string) $credential->certificate_pin);

        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new CertificateException('La llave privada no se pudo abrir.');
        }

        return new SigningKey($key, $certificatePem);
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} certificado PEM, llave PEM y, si hubo que
     *                                                 convertirla, el .p12 nuevo
     */
    private function open(string $p12, string $pin): array
    {
        if ($p12 === '') {
            throw new CertificateException('El archivo de la llave está vacío.');
        }

        $certs = [];

        // Vaciar la cola de errores de OpenSSL: un error viejo de otra
        // operación confundiría el diagnóstico.
        while (openssl_error_string() !== false);

        if (@openssl_pkcs12_read($p12, $certs, $pin)) {
            return [$certs['cert'], $certs['pkey'], null];
        }

        $errors = '';
        while (($error = openssl_error_string()) !== false) {
            $errors .= $error."\n";
        }

        if (str_contains($errors, 'mac verify failure') || str_contains($errors, 'invalid password')) {
            throw new CertificateException('El PIN no corresponde a la llave criptográfica.');
        }

        if (str_contains($errors, 'unsupported') || str_contains($errors, '0308010C')) {
            return $this->openLegacy($p12, $pin);
        }

        throw new CertificateException('El archivo no es una llave criptográfica .p12 válida, o el PIN no corresponde.');
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function openLegacy(string $p12, string $pin): array
    {
        $path = tempnam(sys_get_temp_dir(), 'p12');

        try {
            file_put_contents($path, $p12);
            @chmod($path, 0600);

            $result = Process::env(['PIVOX_P12_PIN' => $pin])
                ->timeout(20)
                ->run(['openssl', 'pkcs12', '-legacy', '-in', $path, '-nodes', '-passin', 'env:PIVOX_P12_PIN']);
        } catch (\Throwable $e) {
            throw new CertificateException(
                'La llave usa un cifrado antiguo y el servidor no tiene el comando openssl para abrirla. '.
                'Pedile al administrador del servidor que lo instale, o exportá la llave de nuevo desde el ATV.',
                previous: $e,
            );
        } finally {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        if (! $result->successful()) {
            $error = $result->errorOutput();

            throw new CertificateException(
                str_contains($error, 'mac verify') || str_contains($error, 'Mac verify')
                    ? 'El PIN no corresponde a la llave criptográfica.'
                    : 'La llave usa un cifrado antiguo y no se pudo abrir: '.mb_substr(trim($error), 0, 200)
            );
        }

        $output = $result->output();

        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $output, $certificates);
        preg_match('/-----BEGIN (?:RSA |ENCRYPTED )?PRIVATE KEY-----.+?-----END (?:RSA |ENCRYPTED )?PRIVATE KEY-----/s', $output, $keyMatch);

        if (empty($certificates[0]) || empty($keyMatch[0])) {
            throw new CertificateException('La llave antigua no trae un certificado y una llave privada.');
        }

        $privateKeyPem = $keyMatch[0];
        $key = openssl_pkey_get_private($privateKeyPem);

        // El archivo puede traer la cadena de la autoridad certificadora: el
        // del contribuyente es el que hace pareja con la llave.
        $certificatePem = null;

        foreach ($certificates[0] as $candidate) {
            if ($key !== false && openssl_x509_check_private_key($candidate, $key)) {
                $certificatePem = $candidate;
                break;
            }
        }

        if ($certificatePem === null || $key === false) {
            throw new CertificateException('La llave privada no corresponde a ninguno de los certificados del archivo.');
        }

        $modern = '';

        if (! openssl_pkcs12_export($certificatePem, $modern, $key, $pin)) {
            throw new CertificateException('No se pudo volver a cifrar la llave con los algoritmos actuales.');
        }

        return [$certificatePem, $privateKeyPem, $modern];
    }

    /**
     * La cédula del titular. El ATV la pone en el serialNumber del sujeto
     * («CPF-01-0123-0456» una física, «CPJ-3-101-123456» una jurídica); a
     * falta de ese campo, se busca en el resto del sujeto.
     *
     * @param  array<string, string|list<string>>  $subject
     */
    private function holderId(array $subject): ?string
    {
        $candidates = [];

        foreach (['serialNumber', 'SERIALNUMBER', '2.5.4.5'] as $field) {
            if (isset($subject[$field])) {
                $candidates = [...$candidates, ...(array) $subject[$field]];
            }
        }

        if ($candidates === []) {
            foreach ($subject as $value) {
                $candidates = [...$candidates, ...(array) $value];
            }
        }

        foreach ($candidates as $value) {
            $digits = ltrim(preg_replace('/\D/', '', (string) $value), '0');

            if (strlen($digits) >= 9 && strlen($digits) <= 12) {
                return $digits;
            }
        }

        return null;
    }

    /**
     * «CN=…, OU=…, O=…, C=CR»: del más específico al más general (RFC 4514),
     * que es al revés de como lo entrega openssl_x509_parse.
     *
     * @param  array<string, string|list<string>>  $name
     */
    public function distinguishedName(array $name): string
    {
        $parts = [];

        foreach ($name as $key => $value) {
            foreach ((array) $value as $single) {
                $parts[] = $key.'='.$single;
            }
        }

        return implode(', ', array_reverse($parts));
    }

    /** @param  array<string, mixed>  $parsed */
    public function serialDecimal(array $parsed): string
    {
        if (isset($parsed['serialNumberHex']) && is_string($parsed['serialNumberHex'])) {
            $decimal = '0';

            foreach (str_split(ltrim($parsed['serialNumberHex'], '0') ?: '0') as $char) {
                $decimal = bcadd(bcmul($decimal, '16'), (string) hexdec($char));
            }

            return $decimal;
        }

        return (string) ($parsed['serialNumber'] ?? '0');
    }
}
