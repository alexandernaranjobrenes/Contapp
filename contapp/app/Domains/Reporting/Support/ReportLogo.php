<?php

namespace App\Domains\Reporting\Support;

/**
 * El tamaño al que se dibuja el logo de la compañía en el encabezado de un
 * documento impreso (los reportes en PDF, el comprobante de pago).
 *
 * El logo entra, sin deformarse, en una caja de BOX_WIDTH × BOX_HEIGHT: uno
 * apaisado ocupa el ancho, uno cuadrado o vertical ocupa el alto. La caja es
 * apaisada porque la mayoría de los logos lo son; con la caja cuadrada de
 * antes (60 × 60), un logo de letras quedaba de 15 px de alto.
 *
 * Las medidas van escritas en el <img> (ancho y alto exactos, ya calculados)
 * en vez de dejarle a dompdf un `max-width`/`max-height`: así el resultado no
 * depende de cómo ese motor resuelva los máximos.
 */
final class ReportLogo
{
    public const BOX_WIDTH = 160;

    public const BOX_HEIGHT = 56;

    /** Separación entre el logo y el nombre de la compañía. */
    private const GAP = 12;

    /**
     * El ancho y el alto, en px, con que se dibuja una imagen de esas
     * medidas. Una más chica que la caja no se agranda.
     *
     * @return array{0: int, 1: int}
     */
    public static function fit(int $width, int $height): array
    {
        $scale = min(1, self::BOX_WIDTH / $width, self::BOX_HEIGHT / $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    /**
     * Lo mismo, leyendo las medidas del logo ya incrustado (`data:…;base64,…`,
     * ver MediaStorage::dataUri). Null si no hay logo o no se pudo medir.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function size(?string $dataUri): ?array
    {
        if ($dataUri === null || ! str_contains($dataUri, ';base64,')) {
            return null;
        }

        $bytes = base64_decode(substr($dataUri, strpos($dataUri, ';base64,') + 8), true);
        $measured = $bytes === false ? false : @getimagesizefromstring($bytes);

        if ($measured === false || $measured[0] < 1 || $measured[1] < 1) {
            return null;
        }

        return self::fit($measured[0], $measured[1]);
    }

    /** El `style` del <img>. Sin medidas conocidas, al menos lo acota a la caja. */
    public static function style(?array $size): string
    {
        return $size === null
            ? 'max-width: '.self::BOX_WIDTH.'px; max-height: '.self::BOX_HEIGHT.'px;'
            : "width: {$size[0]}px; height: {$size[1]}px;";
    }

    /** El ancho de la celda que lo contiene: el del logo más la separación. */
    public static function cellWidth(?array $size): int
    {
        return ($size[0] ?? self::BOX_WIDTH) + self::GAP;
    }
}
