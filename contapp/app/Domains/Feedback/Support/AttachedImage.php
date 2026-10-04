<?php

namespace App\Domains\Feedback\Support;

use App\Domains\Core\Support\UploadedImage;
use Illuminate\Http\UploadedFile;

/**
 * Una imagen adjunta a un comentario o a una noticia: casi siempre una
 * captura de pantalla, o una foto del teléfono.
 *
 * Se guarda vuelta a codificar, como la foto de perfil (ProfilePhoto): se van
 * los metadatos (el GPS de una foto de teléfono) y lo que no sea imagen. Solo
 * se achica si pasa de MAX_SIDE: una captura tiene que seguir leyéndose.
 */
final class AttachedImage
{
    /** Lado mayor, en píxeles, de la imagen guardada. Una más chica no se agranda. */
    public const MAX_SIDE = 2000;

    /** Peso máximo del archivo que se sube, en kilobytes. */
    public const MAX_KILOBYTES = 5120;

    /**
     * Las reglas de validación de un campo con varias imágenes.
     *
     * @return array{rules: array<string, list<string>>, messages: array<string, string>}
     */
    public static function validation(string $field, int $max): array
    {
        $formats = UploadedImage::formatsLabel();
        $megabytes = intdiv(self::MAX_KILOBYTES, 1024);

        return [
            'rules' => [
                $field => ['nullable', 'array', 'max:'.$max],
                "{$field}.*" => [
                    'image',
                    'mimes:'.implode(',', UploadedImage::extensions()),
                    'max:'.self::MAX_KILOBYTES,
                    // Por memoria: para achicarla hay que abrirla entera.
                    'dimensions:max_width=6000,max_height=6000',
                ],
            ],
            'messages' => [
                "{$field}.max" => $max === 1 ? 'Solo se puede adjuntar una imagen.' : "Se pueden adjuntar hasta {$max} imágenes.",
                "{$field}.*.image" => "Solo se pueden adjuntar imágenes en {$formats}.",
                "{$field}.*.mimes" => "Solo se pueden adjuntar imágenes en {$formats}.",
                "{$field}.*.max" => "Cada imagen puede pesar hasta {$megabytes} MB.",
                "{$field}.*.dimensions" => 'Cada imagen puede medir hasta 6000 píxeles de lado.',
                "{$field}.*.uploaded" => 'No se pudo subir la imagen. Probá con un archivo más liviano.',
            ],
        ];
    }

    /**
     * Lo que se le dice al navegador del selector de archivos.
     *
     * @return array{accept: string, formats: string, max_megabytes: int}
     */
    public static function clientRules(): array
    {
        return [
            'accept' => implode(',', UploadedImage::mimeTypes()),
            'formats' => UploadedImage::formatsLabel(),
            'max_megabytes' => intdiv(self::MAX_KILOBYTES, 1024),
        ];
    }

    /**
     * La imagen lista para guardar, en el mismo formato en que llegó. Null si
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

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = self::MAX_SIDE / max($width, $height);

        if ($scale >= 1) {
            return UploadedImage::encode($source, $mime);
        }

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $resized = UploadedImage::canvas($targetWidth, $targetHeight, UploadedImage::hasAlpha($mime));

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return UploadedImage::encode($resized, $mime);
    }
}
