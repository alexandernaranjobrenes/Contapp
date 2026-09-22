<?php

namespace App\Domains\Inventory\DataTransferObjects;

class ItemImportResult
{
    /**
     * @param  string[]  $errors
     */
    public function __construct(
        public readonly int $createdCount,
        public readonly int $updatedCount,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Creados y actualizados van separados porque son operaciones distintas y
     * el usuario necesita distinguirlas: subir un archivo esperando crear 200
     * artículos y que el resumen diga "200 actualizados" es la señal de que se
     * reutilizaron códigos existentes sin querer.
     */
    public function totalCount(): int
    {
        return $this->createdCount + $this->updatedCount;
    }
}
