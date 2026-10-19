<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Backup\Exceptions\InvalidBackupException;

/**
 * El archivo del respaldo: texto, cifrado con la contraseña que elige quien
 * exporta (decidido con el usuario).
 *
 *   CONTAPP RESPALDO CIFRADO
 *   version: 1
 *   compania: …            ← para reconocer el archivo; no es secreto
 *   periodo: …
 *   sal / ops / mem        ← cómo se deriva la clave de la contraseña (Argon2id)
 *   encabezado: …          ← el del flujo cifrado
 *   --
 *   <base64>               ← una línea por tramo cifrado
 *
 * Adentro: las líneas JSON del respaldo (BackupExporter), comprimidas con gzip
 * y cifradas por tramos con XChaCha20-Poly1305 (libsodium secretstream). Cada
 * tramo se autentica: un archivo cortado, reordenado o editado no se abre, y
 * una contraseña equivocada tampoco. Se escribe y se lee de a tramos, sin
 * cargar todo en memoria.
 */
final class BackupFile
{
    public const MAGIC = 'CONTAPP RESPALDO CIFRADO';

    private const CHUNK = 65536;

    /** Límites al leer un encabezado: uno armado para agotar la memoria no pasa. */
    private const MAX_OPS = 10;

    private const MAX_MEM = 268435456;

    public const MIN_PASSWORD = 10;

    /**
     * @param  iterable<string>  $lines
     * @param  resource  $out
     * @param  array{company: string, period: string}  $info
     */
    public static function write(iterable $lines, string $password, array $info, $out): void
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $key = self::key($password, $salt, $ops, $mem);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        sodium_memzero($key);

        fwrite($out, implode("\n", [
            self::MAGIC,
            'version: 1',
            'compania: '.self::oneLine($info['company']),
            'periodo: '.self::oneLine($info['period']),
            'cifrado: xchacha20poly1305-secretstream, gzip',
            'clave: argon2id13',
            'sal: '.base64_encode($salt),
            "ops: {$ops}",
            "mem: {$mem}",
            'encabezado: '.base64_encode($header),
            '--',
        ])."\n");

        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        $buffer = '';

        foreach ($lines as $line) {
            $buffer .= deflate_add($deflate, $line."\n", ZLIB_NO_FLUSH);

            while (strlen($buffer) >= self::CHUNK) {
                $chunk = substr($buffer, 0, self::CHUNK);
                $buffer = substr($buffer, self::CHUNK);
                fwrite($out, base64_encode(sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk))."\n");
            }
        }

        $buffer .= deflate_add($deflate, '', ZLIB_FINISH);

        while (strlen($buffer) > self::CHUNK) {
            fwrite($out, base64_encode(sodium_crypto_secretstream_xchacha20poly1305_push($state, substr($buffer, 0, self::CHUNK)))."\n");
            $buffer = substr($buffer, self::CHUNK);
        }

        fwrite($out, base64_encode(sodium_crypto_secretstream_xchacha20poly1305_push(
            $state, $buffer, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        ))."\n");
    }

    /**
     * Lo visible del archivo, sin la contraseña: para decir qué es antes de
     * pedirla, y para saber cómo derivar la clave.
     *
     * @return array<string, string>
     */
    public static function header(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidBackupException('No se pudo leer el archivo.');
        }

        try {
            return self::readHeader($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Que sea un respaldo de CONTAPP y que la contraseña sea la suya: el
     * encabezado y el primer tramo, nada más. Lo hace la subida, que tiene
     * que contestar enseguida; la revisión completa (descifrar todo, el
     * SHA-256 y la firma) va en la cola.
     *
     * @return array<string, string> el encabezado
     *
     * @throws InvalidBackupException
     */
    public static function check(string $path, string $password): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidBackupException('No se pudo leer el archivo.');
        }

        try {
            $header = self::readHeader($handle);
            $key = self::key($password, base64_decode($header['sal'], true) ?: '', (int) $header['ops'], (int) $header['mem']);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(base64_decode($header['encabezado'], true) ?: '', $key);
            sodium_memzero($key);

            while (($raw = fgets($handle)) !== false && trim($raw) === '') {
                // Las líneas vacías no cuentan.
            }

            if ($raw === false) {
                throw new InvalidBackupException('El archivo está incompleto: se cortó antes del final.');
            }

            $cipher = base64_decode(trim($raw), true);

            if ($cipher === false || sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher) === false) {
                throw new InvalidBackupException('La contraseña no es la de este respaldo, o el archivo está dañado.');
            }

            return $header;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Las líneas JSON del respaldo, descifradas, de a una.
     *
     * @return \Generator<string>
     */
    public static function lines(string $path, string $password): \Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidBackupException('No se pudo leer el archivo.');
        }

        try {
            $header = self::readHeader($handle);
            $key = self::key($password, base64_decode($header['sal'], true) ?: '', (int) $header['ops'], (int) $header['mem']);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(base64_decode($header['encabezado'], true) ?: '', $key);
            sodium_memzero($key);

            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            $pending = '';
            $finished = false;
            $first = true;

            while (($raw = fgets($handle)) !== false) {
                $raw = trim($raw);

                if ($raw === '') {
                    continue;
                }

                if ($finished) {
                    throw new InvalidBackupException('El archivo tiene contenido después del final: no es un respaldo válido.');
                }

                $cipher = base64_decode($raw, true);
                $result = $cipher === false ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);

                if ($result === false) {
                    throw new InvalidBackupException($first
                        ? 'La contraseña no es la de este respaldo, o el archivo está dañado.'
                        : 'El archivo está dañado o fue modificado: no se puede abrir.');
                }

                $first = false;
                [$plain, $tag] = $result;
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                $text = @inflate_add($inflate, $plain, $finished ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

                if ($text === false) {
                    throw new InvalidBackupException('El contenido del archivo está dañado.');
                }

                $pending .= $text;

                while (($newline = strpos($pending, "\n")) !== false) {
                    yield substr($pending, 0, $newline);
                    $pending = substr($pending, $newline + 1);
                }
            }

            if (! $finished) {
                throw new InvalidBackupException('El archivo está incompleto: se cortó antes del final.');
            }

            if ($pending !== '') {
                yield $pending;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array<string, string>
     */
    private static function readHeader($handle): array
    {
        $first = fgets($handle);

        if ($first === false || trim($first) !== self::MAGIC) {
            throw new InvalidBackupException('Este archivo no es un respaldo de CONTAPP.');
        }

        $header = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '--') {
                break;
            }

            if (str_contains($line, ': ')) {
                [$name, $value] = explode(': ', $line, 2);
                $header[$name] = $value;
            }
        }

        foreach (['version', 'sal', 'ops', 'mem', 'encabezado'] as $required) {
            if (! isset($header[$required])) {
                throw new InvalidBackupException('El encabezado del respaldo está incompleto.');
            }
        }

        if ((int) $header['version'] !== 1) {
            throw new InvalidBackupException('Este respaldo es de una versión de CONTAPP que esta no conoce.');
        }

        if ((int) $header['ops'] < 1 || (int) $header['ops'] > self::MAX_OPS || (int) $header['mem'] < 8192 || (int) $header['mem'] > self::MAX_MEM) {
            throw new InvalidBackupException('El encabezado del respaldo no es válido.');
        }

        return $header;
    }

    private static function key(string $password, string $salt, int $ops, int $mem): string
    {
        if (strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new InvalidBackupException('El encabezado del respaldo no es válido.');
        }

        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
