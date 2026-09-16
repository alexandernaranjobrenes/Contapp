<?php

namespace App\Domains\Billing\DataTransferObjects;

/**
 * Encabezado del comprobante tal como lo arma quien factura. Las validaciones
 * que dependen de otros datos (que el cliente exista, que la actividad tenga
 * cuenta, que los pagos cuadren) viven en PostSalesDocumentService, que es
 * quien tiene acceso a la compañía; acá solo lo que se puede verificar con lo
 * que trae el propio objeto.
 *
 * @property SalesLineInput[] $lines
 */
class SalesDocumentInput
{
    public readonly string $exchangeRate;

    public function __construct(
        public readonly int $documentTypeId,
        public readonly string $fiscalDocumentType,
        public readonly int $currencyId,
        public readonly string $saleCondition,
        public readonly \DateTimeInterface $documentDate,
        public readonly \DateTimeInterface $postingDate,
        public readonly array $lines,
        int|float|string $exchangeRate = 1,
        public readonly ?int $businessPartnerId = null,
        public readonly ?int $creditTermDays = null,
        public readonly ?string $emitterActivityCode = null,
        public readonly ?string $receiverActivityCode = null,
        public readonly string $branch = '001',
        public readonly string $terminal = '00001',
        public readonly string $situation = '1',
        /** @var array<int, array{method_code: string, amount: string|float|int}> */
        public readonly array $payments = [],
        /** @var array<int, array<string, mixed>> */
        public readonly array $references = [],
        public readonly ?string $notes = null,
    ) {
        $this->exchangeRate = number_format((float) $exchangeRate, 5, '.', '');
    }
}
