<?php

namespace App\Domains\Pricing\Support;

/**
 * La cotización de un plan (CLAUDE.md secc. 42), sobre el catálogo de
 * PricingCatalog. El mismo cálculo corre en el navegador
 * (resources/js/Utils/pricing.js) para mostrar el precio al instante; el
 * que vale es este, que corre al recibir la solicitud.
 *
 * Las reglas:
 * - Las funciones obligatorias van siempre, y las que una función necesita
 *   se suman solas (Facturación trae Inventario).
 * - Una por cantidad cobra solo las unidades que pasan de las incluidas.
 * - El ciclo lo fija el paquete si se ofrece de una sola forma.
 * - El descuento del paquete vale sobre lo que trae el paquete, mientras
 *   no se le quite nada: lo agregado va a precio de lista; quitar una
 *   función suya lo vuelve «Personalizado», sin descuento. Así nadie elige
 *   el paquete grande para llevarse el descuento en una sola función.
 * - El IVA, con la tasa de los ajustes: sumado al neto, o ya incluido.
 *
 * Todo en céntimos enteros: nada de decimales en coma flotante.
 */
final class PricingCalculator
{
    public const CYCLES = ['monthly', 'annual'];

    /**
     * @param  array{features: list<array<string, mixed>>, packages: list<array<string, mixed>>, settings: array<string, mixed>}  $catalog
     * @param  array<string, mixed>  $selection  clave de la función => cantidad (0 = no)
     * @return array<string, mixed>
     */
    public static function quote(array $catalog, ?string $packageSlug, array $selection, string $cycle, string $currency): array
    {
        $features = [];
        foreach ($catalog['features'] as $feature) {
            $features[$feature['key']] = $feature;
        }

        $package = null;
        foreach ($catalog['packages'] as $candidate) {
            if ($candidate['slug'] === $packageSlug) {
                $package = $candidate;
            }
        }

        if ($package !== null && $package['billing'] !== 'both') {
            $cycle = $package['billing'];
        }

        if (! in_array($cycle, self::CYCLES, true)) {
            $cycle = 'monthly';
        }

        if (! in_array($currency, ['CRC', 'USD'], true)) {
            $currency = $catalog['settings']['currency'];
        }

        [$quantities, $added] = self::normalize($features, $selection);

        $lines = [];
        $subtotal = 0;

        foreach ($features as $key => $feature) {
            $quantity = $quantities[$key];

            if ($quantity <= 0) {
                continue;
            }

            $unit = $feature['prices'][$currency][$cycle];
            $billable = self::billable($feature, $quantity);
            $amount = $unit * $billable;
            $subtotal += $amount;

            $lines[] = [
                'key' => $key,
                'name' => $feature['name'],
                'kind' => $feature['kind'],
                'quantity' => $quantity,
                'billable' => $billable,
                'unit' => $unit,
                'amount' => $amount,
                'unit_label' => $feature['kind'] === 'quantity'
                    ? ($quantity === 1 ? $feature['unit_singular'] : $feature['unit_plural'])
                    : null,
            ];
        }

        $packageFeatures = $package ? (array) $package['features'] : [];
        $intact = $package !== null && collect($packageFeatures)->every(fn ($quantity, $key) => ($quantities[$key] ?? 0) >= $quantity);
        $discount = 0;

        if ($intact && $package['discount_bp'] > 0) {
            $base = 0;

            foreach ($packageFeatures as $key => $quantity) {
                if (isset($features[$key])) {
                    $base += $features[$key]['prices'][$currency][$cycle] * self::billable($features[$key], $quantity);
                }
            }

            $discount = (int) round($base * $package['discount_bp'] / 10000);
        }

        $net = $subtotal - $discount;
        $taxBp = (int) $catalog['settings']['tax_bp'];

        if ($catalog['settings']['prices_include_tax']) {
            $tax = $net - (int) round($net * 10000 / (10000 + $taxBp));
            $total = $net;
        } else {
            $tax = (int) round($net * $taxBp / 10000);
            $total = $net + $tax;
        }

        return [
            'package' => $package ? ['slug' => $package['slug'], 'name' => $package['name'], 'discount_bp' => $package['discount_bp']] : null,
            'intact' => $intact,
            'cycle' => $cycle,
            'currency' => $currency,
            'lines' => $lines,
            'added' => $added,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'net' => $net,
            'tax' => $tax,
            'tax_rate' => $catalog['settings']['tax_rate'],
            'prices_include_tax' => (bool) $catalog['settings']['prices_include_tax'],
            'total' => $total,
            'quantities' => $quantities,
        ];
    }

    /** Lo que trae un paquete, como selección para empezar a cotizar. */
    public static function packageSelection(array $catalog, string $slug): array
    {
        foreach ($catalog['packages'] as $package) {
            if ($package['slug'] === $slug) {
                return (array) $package['features'];
            }
        }

        return [];
    }

    /**
     * Las cantidades válidas: con su tope, con las obligatorias y con lo que
     * cada una necesita. Devuelve también lo que se sumó solo.
     *
     * @param  array<string, array<string, mixed>>  $features
     * @param  array<string, mixed>  $selection
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private static function normalize(array $features, array $selection): array
    {
        $quantities = [];

        foreach ($features as $key => $feature) {
            $raw = max(0, (int) ($selection[$key] ?? 0));

            $quantity = $feature['kind'] === 'quantity'
                ? min($raw, $feature['max'] ?? PHP_INT_MAX)
                : ($raw > 0 ? 1 : 0);

            $quantities[$key] = max($quantity, $feature['required'] ? self::minimum($feature) : 0);
        }

        $added = [];

        do {
            $changed = false;

            foreach ($features as $key => $feature) {
                if ($quantities[$key] === 0) {
                    continue;
                }

                foreach ($feature['requires'] as $needed) {
                    if (isset($quantities[$needed]) && $quantities[$needed] === 0) {
                        $quantities[$needed] = self::minimum($features[$needed]);
                        $added[] = $needed;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return [$quantities, array_values(array_unique($added))];
    }

    /** Lo mínimo de una función que va sí o sí. */
    private static function minimum(array $feature): int
    {
        return $feature['kind'] === 'quantity' ? max(1, (int) $feature['included']) : 1;
    }

    /** Las unidades que se cobran: las que pasan de las incluidas. */
    private static function billable(array $feature, int $quantity): int
    {
        return $feature['kind'] === 'quantity' ? max(0, $quantity - (int) $feature['included']) : 1;
    }
}
