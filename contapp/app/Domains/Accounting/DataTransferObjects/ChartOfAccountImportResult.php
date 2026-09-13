<?php

namespace App\Domains\Accounting\DataTransferObjects;

class ChartOfAccountImportResult
{
    /**
     * @param  string[]  $errors
     */
    public function __construct(
        public readonly int $importedCount,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
