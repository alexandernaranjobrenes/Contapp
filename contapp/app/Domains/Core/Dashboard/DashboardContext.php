<?php

namespace App\Domains\Core\Dashboard;

use App\Domains\Core\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Lo que comparten los elementos de un Panel al armarse: la compañía, quién
 * lo mira, el día de hoy en Costa Rica y la moneda local. remember() guarda
 * un cálculo para los demás elementos de la misma visita (los ingresos por
 * mes los usan dos).
 */
final class DashboardContext
{
    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * @param  array{code: string, symbol: string}  $currency
     */
    public function __construct(
        public readonly Company $company,
        public readonly User $user,
        public readonly Carbon $today,
        public readonly array $currency,
    ) {}

    public static function for(Company $company, User $user): self
    {
        $currency = $company->localCurrency;

        return new self(
            $company,
            $user,
            Carbon::now('America/Costa_Rica')->startOfDay(),
            ['code' => $currency?->code ?? 'CRC', 'symbol' => $currency?->symbol ?? '₡'],
        );
    }

    public function remember(string $key, Closure $callback): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $callback();
        }

        return $this->memo[$key];
    }

    /** Un monto para la pantalla: número, con dos decimales. */
    public static function money(string|int|float|null $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];

    /** El mes, corto y largo: «oct» y «octubre de 2026». */
    public static function monthLabels(Carbon $month): array
    {
        $name = self::MONTHS[$month->month - 1];

        return ['label' => mb_substr($name, 0, 3), 'title' => "{$name} de {$month->year}"];
    }

    /** Un día, corto: «15 oct». */
    public static function dayLabel(Carbon $date): string
    {
        return $date->day.' '.mb_substr(self::MONTHS[$date->month - 1], 0, 3);
    }
}
