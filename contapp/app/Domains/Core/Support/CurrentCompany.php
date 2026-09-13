<?php

namespace App\Domains\Core\Support;

use App\Domains\Core\Models\Company;

/**
 * Holds the active company for the current request/console execution.
 * Bound as a singleton; every BelongsToCompany model scopes reads/writes through it.
 */
class CurrentCompany
{
    protected ?int $id = null;

    /**
     * Licencia vencida (no revocada) de la compañía activa — ver
     * SetCurrentCompany y EnforceLicenseGracePeriod (CLAUDE.md secc. 13:
     * "modo de gracia", nunca un corte abrupto solo por vencimiento).
     */
    protected bool $graceMode = false;

    public function set(int|Company $company): void
    {
        $this->id = $company instanceof Company ? $company->id : $company;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function isSet(): bool
    {
        return $this->id !== null;
    }

    public function clear(): void
    {
        $this->id = null;
        $this->graceMode = false;
    }

    public function setGraceMode(bool $inGrace): void
    {
        $this->graceMode = $inGrace;
    }

    public function isInGracePeriod(): bool
    {
        return $this->graceMode;
    }
}
