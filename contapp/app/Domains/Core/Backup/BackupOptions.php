<?php

namespace App\Domains\Core\Backup;

use App\Domains\Core\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Qué se respalda: de qué compañía, qué secciones y de qué período.
 *
 * - Sin «desde» ni «hasta»: todo el historial.
 * - Con «desde»: los movimientos desde ese día, y los saldos al día
 *   anterior (BackupOpening), para que la compañía importada arranque bien.
 * - Con «hasta» antes de hoy: los movimientos hasta ese día, y el estado de
 *   ese día (existencias, partidas abiertas…, BackupState).
 */
final class BackupOptions
{
    /** @var list<string> */
    public readonly array $sections;

    public readonly ?Carbon $from;

    public readonly ?Carbon $to;

    /**
     * @param  list<string>  $sections
     */
    public function __construct(
        public readonly Company $company,
        array $sections,
        ?string $from = null,
        ?string $to = null,
    ) {
        $this->sections = BackupCatalog::resolveSections($sections);

        $timezone = $company->timezone ?: 'America/Costa_Rica';
        $this->from = $from ? Carbon::parse($from, $timezone)->startOfDay() : null;
        // «Hasta» hoy o después es lo mismo que sin límite: el estado de hoy.
        $to = $to ? Carbon::parse($to, $timezone)->startOfDay() : null;
        $this->to = $to !== null && $to->lt(Carbon::today($timezone)) ? $to : null;
    }

    public function hasSection(string $section): bool
    {
        return in_array($section, $this->sections, true);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return BackupCatalog::tablesFor($this->sections);
    }

    /** El día anterior al período: la fecha de los saldos iniciales. */
    public function openingDate(): ?Carbon
    {
        return $this->from?->copy()->subDay();
    }

    public function fromDate(): ?string
    {
        return $this->from?->format('Y-m-d');
    }

    public function toDate(): ?string
    {
        return $this->to?->format('Y-m-d');
    }

    /** Lo que la persona eligió como «hasta», aunque sea hoy: para mostrar. */
    public function describePeriod(): string
    {
        return match (true) {
            $this->from === null && $this->to === null => 'Todo el historial',
            $this->from === null => 'Hasta el '.$this->to->format('d/m/Y'),
            $this->to === null => 'Desde el '.$this->from->format('d/m/Y').' hasta hoy',
            default => 'Del '.$this->from->format('d/m/Y').' al '.$this->to->format('d/m/Y'),
        };
    }

    /** @return array{sections: list<string>, from: ?string, to: ?string} */
    public function toArray(): array
    {
        return ['sections' => $this->sections, 'from' => $this->fromDate(), 'to' => $this->toDate()];
    }
}
