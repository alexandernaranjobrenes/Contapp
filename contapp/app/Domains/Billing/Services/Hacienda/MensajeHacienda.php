<?php

namespace App\Domains\Billing\Services\Hacienda;

/**
 * La respuesta firmada de Hacienda (MensajeHacienda), ya leída. El código va
 * en el elemento Mensaje: 1 aceptado, 2 aceptado parcialmente, 3 rechazado. El
 * motivo, en DetalleMensaje.
 *
 * Se lee por nombre local y no por namespace: el namespace cambia con cada
 * versión del anexo, y el nombre de estos elementos no.
 */
final class MensajeHacienda
{
    public function __construct(
        public readonly ?string $code,
        public readonly ?string $detail,
        public readonly ?string $clave,
    ) {}

    public static function parse(?string $xml): ?self
    {
        if ($xml === null || trim($xml) === '') {
            return null;
        }

        $dom = new \DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $value = fn (string $name) => ($node = $xpath->query("/*/*[local-name()='{$name}']")->item(0))
            ? trim($node->textContent)
            : null;

        return new self(
            code: $value('Mensaje'),
            detail: $value('DetalleMensaje'),
            clave: $value('Clave'),
        );
    }
}
