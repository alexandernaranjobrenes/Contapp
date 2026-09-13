<?php

namespace App\Domains\Accounting\DataTransferObjects;

class JournalEntryImportResult
{
    /**
     * @param  string[]  $errors
     */
    public function __construct(
        public readonly ?int $journalEntryId,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
