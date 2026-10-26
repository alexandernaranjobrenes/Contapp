<?php

namespace App\Domains\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Los ajustes de la página de precios (CLAUDE.md secc. 42): una sola fila.
 *
 * - default_currency: la moneda con que abre la página (CRC o USD); el
 *   visitante puede cambiarla.
 * - tax_rate y prices_include_tax: el IVA que se muestra en la cotización,
 *   y si los precios ya lo incluyen. La tasa sale de acá, nunca del código
 *   (CLAUDE.md secc. 7).
 * - notification_email: adónde llega el aviso de una solicitud nueva.
 */
class PricingSetting extends Model
{
    public const CURRENCIES = ['CRC', 'USD'];

    protected $fillable = ['default_currency', 'tax_rate', 'prices_include_tax', 'notification_email'];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
        ];
    }

    /** La fila de ajustes; la primera vez nace con los valores de config/site.php. */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'default_currency' => 'CRC',
            'tax_rate' => 13,
            'prices_include_tax' => false,
            'notification_email' => config('site.requests_email'),
        ]);
    }

    /** La tasa en puntos básicos (13 % → 1300), para calcular sin decimales. */
    public function taxBasisPoints(): int
    {
        return (int) round((float) $this->tax_rate * 100);
    }
}
