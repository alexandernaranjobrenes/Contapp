<?php

namespace App\Domains\Inventory\DataTransferObjects;

use App\Domains\Inventory\Models\PriceList;

/**
 * El resultado de buscar el precio de un artículo para un cliente y una
 * fecha. Siempre trae MOTIVO: un campo vacío sin explicación obliga a
 * adivinar si el artículo no tiene precio, si la lista venció o si el
 * cliente quedó sin lista asignada, y cada una de esas se arregla en un
 * lugar distinto.
 */
class PriceResolution
{
    private function __construct(
        public readonly ?string $unitPrice,
        public readonly ?PriceList $priceList,
        public readonly string $reason,
        public readonly bool $pricesIncludeTax = false,
    ) {}

    public static function found(string $unitPrice, PriceList $list): self
    {
        return new self($unitPrice, $list, 'found', $list->prices_include_tax);
    }

    /**
     * @param  string  $reason  por qué no hay precio, en clave estable para
     *                          que la pantalla arme el mensaje
     */
    public static function none(string $reason, ?PriceList $list = null): self
    {
        return new self(null, $list, $reason);
    }

    public function hasPrice(): bool
    {
        return $this->unitPrice !== null;
    }

    /**
     * El texto que la pantalla muestra cuando no hay precio. Cada caso se
     * arregla en un lugar distinto y el mensaje dice cuál.
     */
    public function message(): string
    {
        return match ($this->reason) {
            'found' => '',
            'no_list' => 'No hay lista de precios que aplique: el cliente no tiene una propia, su categoría '.
                'tampoco, y la compañía no tiene lista predeterminada.',
            'list_not_valid' => 'La lista '.($this->priceList?->code ?? '').' no está vigente en esta fecha.',
            'currency_mismatch' => 'La lista '.($this->priceList?->code ?? '').' está en otra moneda que el documento; '.
                'el precio no se convierte solo para no cambiar de valor con el tipo de cambio del día.',
            'item_not_in_list' => 'El artículo no tiene precio en la lista '.($this->priceList?->code ?? '').'.',
            default => 'No se encontró precio.',
        };
    }
}
