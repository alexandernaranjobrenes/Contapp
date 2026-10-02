<?php

namespace App\Domains\Core\Support;

use Illuminate\Http\UploadedFile;

/**
 * Deja lista una foto de perfil antes de guardarla: derecha, cuadrada y de
 * un tamaño razonable.
 *
 * No se guarda el archivo tal como llega, por tres razones:
 *
 *  - La dirección de una foto de perfil es pública y fácil de adivinar
 *    («users/profile_7.jpg»). Una foto de teléfono trae adentro dónde y
 *    cuándo se tomó (EXIF, con GPS). Volver a codificarla deja solo los
 *    píxeles.
 *  - Se muestra en un círculo chico. Guardar los 4 MB originales es pagar
 *    almacenamiento y ancho de banda por píxeles que nadie ve.
 *  - Una foto vertical recortada a cuadrado en el servidor se ve igual en
 *    todas las pantallas, sin depender de cómo recorte cada una.
 *
 * Abrir, enderezar y volver a escribir la imagen es de UploadedImage, que
 * también usa el logo de la compañía (CompanyLogo).
 */
final class ProfilePhoto
{
    /** Lado, en píxeles, de la foto guardada. Una más chica no se agranda. */
    public const SIZE = 512;

    /** Peso máximo del archivo que se sube, en kilobytes. */
    public const MAX_KILOBYTES = 4096;

    /** @return list<string> */
    public static function extensions(): array
    {
        return UploadedImage::extensions();
    }

    /** @return list<string> */
    public static function mimeTypes(): array
    {
        return UploadedImage::mimeTypes();
    }

    public static function formatsLabel(): string
    {
        return UploadedImage::formatsLabel();
    }

    /**
     * La foto lista para guardar, en el mismo formato en que llegó. Null si
     * el archivo no se pudo leer como imagen.
     *
     * @return array{contents: string, extension: string}|null
     */
    public static function normalize(UploadedFile $file): ?array
    {
        $opened = UploadedImage::open($file);

        if ($opened === null) {
            return null;
        }

        ['image' => $source, 'mime' => $mime] = $opened;

        // El cuadrado central, reducido a SIZE si es más grande.
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = min(self::SIZE, $side);

        // Un PNG con fondo transparente lo conserva.
        $square = UploadedImage::canvas($target, $target, UploadedImage::hasAlpha($mime));

        imagecopyresampled(
            $square, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            $target, $target,
            $side, $side,
        );

        return UploadedImage::encode($square, $mime);
    }
}
