<?php

namespace App\Domains\Core\Support;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Las imágenes que sube la gente: la fotografía de un empleado, el logo de
 * una compañía.
 *
 * Viven en el disco que diga `filesystems.media` —el bucket de Cloudflare R2,
 * o el disco público local— y esta es la única clase que lo nombra. En la
 * base se guarda la ruta relativa («employees/7/abc.jpg»), igual en cualquier
 * disco, así que cambiar de disco es copiar los archivos, sin tocar la base.
 *
 * ── Un disco remoto no es una carpeta ───────────────────────────────────
 *
 * Tres cosas que en disco local daban igual y contra un bucket no:
 *
 *  - Preguntar si un archivo existe es una petición HTTP. Para armar una
 *    URL no se pregunta: en un listado sería una petición por fila.
 *  - No hay ruta en disco. El PDF necesita los bytes (dompdf no baja
 *    imágenes por HTTP), así que se le entregan incrustados: dataUri().
 *  - Puede fallar por la red o por las credenciales. Ningún fallo se traga:
 *    se reporta al log con su causa y quien llama recibe null.
 */
class MediaStorage
{
    /**
     * Guarda un archivo subido, con nombre aleatorio, y devuelve su ruta.
     * Null si no se pudo guardar.
     */
    public function store(UploadedFile $file, string $directory): ?string
    {
        try {
            $path = $file->store($directory, $this->diskName());
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        // Un disco con `throw` apagado no lanza: devuelve false.
        return $path === false ? null : $path;
    }

    /**
     * Guarda bytes ya preparados en una ruta que decide quien llama (la foto
     * de perfil: una por cuenta, con nombre fijo). Si ya había un archivo en
     * esa ruta, lo reemplaza. False si no se pudo guardar.
     */
    public function put(string $path, string $contents): bool
    {
        try {
            return (bool) $this->disk()->put($path, $contents);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Borra un archivo que ya nadie usa. Si no se puede, queda huérfano en el
     * disco: no es motivo para que falle lo que se estaba haciendo.
     */
    public function delete(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->disk()->delete($path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** La dirección pública, para un <img> en pantalla. */
    public function url(?string $path): ?string
    {
        return $path === null ? null : $this->disk()->url($path);
    }

    /**
     * La dirección pública de un archivo que se reemplaza sin cambiar de
     * nombre. Lleva la fecha del último cambio (?v=…): sin ella el navegador
     * mostraría la versión anterior desde su caché, porque la dirección
     * sería la misma.
     */
    public function versionedUrl(?string $path, ?DateTimeInterface $updatedAt): ?string
    {
        $url = $this->url($path);

        return $url === null || $updatedAt === null ? $url : $url.'?v='.$updatedAt->getTimestamp();
    }

    /**
     * La imagen incrustada como `data:`, para un PDF. Baja el archivo, así
     * que se pide solo al armar el PDF, nunca para una pantalla. Null si el
     * archivo no está: el documento sale igual, sin la imagen.
     */
    public function dataUri(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        try {
            $bytes = $this->disk()->get($path);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($bytes === null) {
            return null;
        }

        return 'data:'.$this->mimeType($path).';base64,'.base64_encode($bytes);
    }

    private function mimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    private function diskName(): string
    {
        return config('filesystems.media');
    }
}
