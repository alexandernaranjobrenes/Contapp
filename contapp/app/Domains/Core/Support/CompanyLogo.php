<?php

namespace App\Domains\Core\Support;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Deja listo el logo de una compañía antes de guardarlo, pensando en dónde
 * se usa: el encabezado de los reportes (PDF, comprobante de pago,
 * presentación del asiento).
 *
 * A diferencia de la foto de perfil, un logo no se recorta a cuadrado: tiene
 * la forma que tiene (apaisado, cuadrado, vertical) y deformarlo o cortarlo
 * sería cambiar la marca. Lo que se hace es:
 *
 *  1. Quitarle el margen vacío de alrededor. Un logo exportado con aire a
 *     los costados —transparente o blanco— se vería chico en el reporte,
 *     porque el espacio del encabezado se le iría en ese aire. Solo se
 *     recorta lo que sobre papel blanco no se ve: fondo transparente o
 *     blanco. Un fondo de color es parte del logo y no se toca.
 *  2. Reducirlo, sin deformarlo, para que entre en MAX_WIDTH × MAX_HEIGHT.
 *     Es unas cuatro veces el tamaño al que se dibuja en un reporte (ver
 *     ReportLogo): nitidez de sobra para imprimir, sin que cada PDF cargue
 *     con una imagen de varios megabytes.
 *
 * Y, como con toda imagen subida, se vuelve a codificar (UploadedImage): se
 * guarda sin metadatos.
 */
final class CompanyLogo
{
    /** El logo guardado entra en este rectángulo, en píxeles. */
    public const MAX_WIDTH = 600;

    public const MAX_HEIGHT = 240;

    /**
     * El lado más largo tiene que medir al menos esto, ya sin márgenes. Más
     * chico se vería borroso al imprimir.
     */
    public const MIN_LONG_SIDE = 100;

    /** Peso máximo del archivo que se sube, en kilobytes. */
    public const MAX_KILOBYTES = 4096;

    /**
     * Antes de buscar los márgenes se reduce a este lado: recorrer píxel por
     * píxel una imagen de 6000 px tardaría segundos.
     */
    private const WORK_SIDE = 1200;

    /**
     * El logo listo para guardar, en el mismo formato en que llegó, con sus
     * medidas finales. Null si el archivo no se pudo leer como imagen o si
     * está vacío (todo transparente o todo blanco).
     *
     * @return array{contents: string, extension: string, width: int, height: int}|null
     */
    public static function normalize(UploadedFile $file): ?array
    {
        $opened = UploadedImage::open($file);

        if ($opened === null) {
            return null;
        }

        ['image' => $image, 'mime' => $mime] = $opened;
        $transparent = UploadedImage::hasAlpha($mime);

        $image = self::fitted($image, self::WORK_SIDE, self::WORK_SIDE, $transparent);
        $image = self::trimmed($image, $transparent);

        if ($image === null) {
            return null;
        }

        $image = self::fitted($image, self::MAX_WIDTH, self::MAX_HEIGHT, $transparent);
        $encoded = UploadedImage::encode($image, $mime);

        return $encoded === null ? null : [...$encoded, 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    /** La imagen reducida, sin deformarla, para que entre en el rectángulo. Nunca la agranda. */
    private static function fitted(GdImage $image, int $maxWidth, int $maxHeight, bool $transparent): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);

        if ($scale >= 1) {
            return $image;
        }

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = UploadedImage::canvas($targetWidth, $targetHeight, $transparent);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    /**
     * La imagen sin el margen vacío de alrededor. La misma si no tiene un
     * fondo que se pueda quitar; null si es toda fondo.
     */
    private static function trimmed(GdImage $image, bool $transparent): ?GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $isBackground = self::backgroundTest($image, $width, $height);

        if ($isBackground === null) {
            return $image;
        }

        $rowIsEmpty = function (int $y) use ($image, $width, $isBackground): bool {
            for ($x = 0; $x < $width; $x++) {
                if (! $isBackground(imagecolorat($image, $x, $y))) {
                    return false;
                }
            }

            return true;
        };

        // De cada borde hacia adentro, hasta la primera fila o columna que
        // tiene algo: en un logo normal son pocas pasadas.
        $top = 0;
        while ($top < $height && $rowIsEmpty($top)) {
            $top++;
        }

        if ($top === $height) {
            return null;
        }

        $bottom = $height - 1;
        while ($bottom > $top && $rowIsEmpty($bottom)) {
            $bottom--;
        }

        $columnIsEmpty = function (int $x) use ($image, $top, $bottom, $isBackground): bool {
            for ($y = $top; $y <= $bottom; $y++) {
                if (! $isBackground(imagecolorat($image, $x, $y))) {
                    return false;
                }
            }

            return true;
        };

        $left = 0;
        while ($left < $width - 1 && $columnIsEmpty($left)) {
            $left++;
        }

        $right = $width - 1;
        while ($right > $left && $columnIsEmpty($right)) {
            $right--;
        }

        $trimmedWidth = $right - $left + 1;
        $trimmedHeight = $bottom - $top + 1;

        if ($trimmedWidth === $width && $trimmedHeight === $height) {
            return $image;
        }

        $canvas = UploadedImage::canvas($trimmedWidth, $trimmedHeight, $transparent);
        imagecopy($canvas, $image, 0, 0, $left, $top, $trimmedWidth, $trimmedHeight);

        return $canvas;
    }

    /**
     * Cómo reconocer el fondo de esta imagen, mirando sus cuatro esquinas:
     * todas transparentes, o todas blancas. Null si las esquinas tienen
     * color (el fondo es parte del logo) o no coinciden entre sí.
     *
     * El fondo es de una sola clase a propósito: en un logo de letras
     * blancas sobre fondo transparente, lo blanco es el logo.
     *
     * @return (callable(int): bool)|null
     */
    private static function backgroundTest(GdImage $image, int $width, int $height): ?callable
    {
        // GD guarda la transparencia de 0 (opaco) a 127 (transparente).
        $isTransparent = fn (int $color): bool => (($color >> 24) & 0x7F) >= 100;

        $isWhite = fn (int $color): bool => (($color >> 24) & 0x7F) < 100
            && (($color >> 16) & 0xFF) >= 244
            && (($color >> 8) & 0xFF) >= 244
            && ($color & 0xFF) >= 244;

        $corners = [
            imagecolorat($image, 0, 0),
            imagecolorat($image, $width - 1, 0),
            imagecolorat($image, 0, $height - 1),
            imagecolorat($image, $width - 1, $height - 1),
        ];

        foreach ([$isTransparent, $isWhite] as $test) {
            if (count(array_filter($corners, $test)) === 4) {
                return $test;
            }
        }

        return null;
    }
}
