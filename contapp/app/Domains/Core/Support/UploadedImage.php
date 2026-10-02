<?php

namespace App\Domains\Core\Support;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Lo común a toda imagen que sube la gente y que se vuelve a codificar antes
 * de guardarla (la foto de perfil, el logo de una compañía): abrirla,
 * enderezarla y escribirla de nuevo en su mismo formato.
 *
 * Volver a codificar deja solo los píxeles: se van los metadatos que trae el
 * archivo (una foto de teléfono lleva adentro el GPS de donde se tomó).
 *
 * Usa GD, que es lo que trae la imagen de PHP del proyecto. Los formatos
 * aceptados son los que ese GD sabe leer Y escribir: JPG y PNG siempre, WebP
 * solo si está compilado con soporte.
 */
final class UploadedImage
{
    /**
     * Extensiones aceptadas, para la regla `mimes` de la validación.
     *
     * @return list<string>
     */
    public static function extensions(): array
    {
        return self::supportsWebp() ? ['jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png'];
    }

    /**
     * Lo mismo, para el atributo `accept` del selector de archivos.
     *
     * @return list<string>
     */
    public static function mimeTypes(): array
    {
        return self::supportsWebp() ? ['image/jpeg', 'image/png', 'image/webp'] : ['image/jpeg', 'image/png'];
    }

    /** Cómo se le dice a la persona qué puede subir: «JPG o PNG». */
    public static function formatsLabel(): string
    {
        return self::supportsWebp() ? 'JPG, PNG o WebP' : 'JPG o PNG';
    }

    /**
     * La imagen abierta, a color verdadero y ya derecha, con su formato.
     * Null si el archivo no se pudo leer como imagen.
     *
     * @return array{image: GdImage, mime: string}|null
     */
    public static function open(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();
        $mime = $file->getMimeType();

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => self::supportsWebp() ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (! $image instanceof GdImage) {
            return null;
        }

        // Un PNG de paleta guarda índices, no colores: se pasa a color
        // verdadero para poder leer y copiar cada píxel con su transparencia.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        if ($mime === 'image/jpeg') {
            $image = self::upright($image, $path);
        }

        return ['image' => $image, 'mime' => $mime];
    }

    /**
     * La imagen escrita en su formato, lista para guardar. Null si no se pudo.
     *
     * @return array{contents: string, extension: string}|null
     */
    public static function encode(GdImage $image, string $mime): ?array
    {
        ob_start();
        $written = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 88),
            'image/png' => imagepng($image, null, 6),
            'image/webp' => self::supportsWebp() && imagewebp($image, null, 88),
            default => false,
        };
        $contents = ob_get_clean();

        if (! $written || $contents === false || $contents === '') {
            return null;
        }

        return [
            'contents' => $contents,
            'extension' => match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            },
        ];
    }

    /**
     * Un lienzo nuevo. Con $transparent arranca transparente, para copiarle
     * encima una imagen que conserva su transparencia (PNG, WebP); sin él,
     * blanco, que es el fondo que termina teniendo un JPG.
     */
    public static function canvas(int $width, int $height, bool $transparent): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, $transparent
            ? imagecolorallocatealpha($canvas, 0, 0, 0, 127)
            : imagecolorallocate($canvas, 255, 255, 255));

        return $canvas;
    }

    /** ¿Este formato puede tener partes transparentes? */
    public static function hasAlpha(string $mime): bool
    {
        return $mime !== 'image/jpeg';
    }

    /**
     * Un teléfono guarda la foto como la vio el sensor y anota aparte cómo
     * hay que girarla. Al volver a codificar esa nota se pierde, así que el
     * giro se aplica acá, a los píxeles.
     */
    private static function upright(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        // imagerotate gira en sentido antihorario.
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, 270, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        $image = $rotated instanceof GdImage ? $rotated : $image;

        // Las orientaciones pares, además, vienen espejadas.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private static function supportsWebp(): bool
    {
        return function_exists('imagecreatefromwebp') && function_exists('imagewebp');
    }
}
