<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Core\Models\Company;

/**
 * Consecutivo de 20 dígitos y clave numérica de 50, según el Anexo Técnico
 * v4.4. Ambos tienen posiciones fijas y longitud exacta: un dígito de más o de
 * menos hace que Hacienda rechace el comprobante, así que cada tramo se rellena
 * explícitamente y el resultado se verifica antes de devolverse.
 *
 *   Consecutivo (20) = sucursal(3) + terminal(5) + tipo(2) + número(10)
 *   Clave (50)       = país(3) + día(2) + mes(2) + año(2) + cédula(12)
 *                      + consecutivo(20) + situación(1) + código seguridad(8)
 */
class FiscalKeyGenerator
{
    private const COUNTRY_CODE = '506';

    public function consecutive(string $branch, string $terminal, string $fiscalDocumentType, int $number): string
    {
        if ($number < 1 || $number > 9999999999) {
            throw new InvalidSalesDocumentException("El consecutivo fiscal {$number} está fuera del rango de 10 dígitos.");
        }

        $value = $this->pad($branch, 3)
            .$this->pad($terminal, 5)
            .$this->pad($fiscalDocumentType, 2)
            .$this->pad((string) $number, 10);

        return $this->assertLength($value, 20, 'consecutivo');
    }

    /**
     * La cédula del emisor va a 12 dígitos rellenando con ceros a la izquierda:
     * una jurídica costarricense tiene 10 y una física 9, y el XML exige los 12.
     */
    public function clave(
        Company $company,
        \DateTimeInterface $issuedAt,
        string $consecutive,
        string $situation,
        string $securityCode,
    ): string {
        $taxId = preg_replace('/\D/', '', (string) $company->tax_id);

        if ($taxId === '' || $taxId === null) {
            throw new InvalidSalesDocumentException(
                "La compañía {$company->legal_name} no tiene cédula jurídica registrada; sin ella no se puede armar la clave numérica."
            );
        }

        if (strlen($taxId) > 12) {
            throw new InvalidSalesDocumentException('La cédula del emisor excede los 12 dígitos que admite la clave numérica.');
        }

        $this->assertLength($consecutive, 20, 'consecutivo');
        $this->assertLength($securityCode, 8, 'código de seguridad');

        if (! array_key_exists($situation, FiscalCatalogs::SITUATIONS)) {
            throw new InvalidSalesDocumentException("Situación del comprobante desconocida: {$situation}.");
        }

        $value = self::COUNTRY_CODE
            .$issuedAt->format('d')
            .$issuedAt->format('m')
            .$issuedAt->format('y')
            .$this->pad($taxId, 12)
            .$consecutive
            .$situation
            .$securityCode;

        return $this->assertLength($value, 50, 'clave numérica');
    }

    /**
     * Ocho dígitos aleatorios. Su función es impedir que un tercero adivine
     * claves ajenas a partir de una propia, así que se usa un generador
     * criptográficamente seguro y no `rand()`.
     */
    public function securityCode(): string
    {
        return $this->pad((string) random_int(0, 99999999), 8);
    }

    private function pad(string $value, int $length): string
    {
        return str_pad($value, $length, '0', STR_PAD_LEFT);
    }

    private function assertLength(string $value, int $expected, string $label): string
    {
        if (strlen($value) !== $expected) {
            throw new InvalidSalesDocumentException(
                "El {$label} quedó con ".strlen($value)." dígitos en vez de {$expected}: {$value}"
            );
        }

        if (! ctype_digit($value)) {
            throw new InvalidSalesDocumentException("El {$label} debe ser numérico: {$value}");
        }

        return $value;
    }
}
