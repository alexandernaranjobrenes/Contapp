<?php

namespace App\Domains\Pricing\Support;

/**
 * Un monto de la página de precios, como en la aplicación: punto de
 * millares y coma decimal (Utils/money.js), con el símbolo de la moneda.
 * Sin decimales si es redondo: «₡25.000», «US$49», «₡12.345,50».
 * El mismo formato tiene formatPrice() en resources/js/Utils/pricing.js.
 */
final class PricingMoney
{
    public static function format(int $cents, string $currency): string
    {
        $whole = $cents % 100 === 0;
        $number = number_format(abs($cents) / 100, $whole ? 0 : 2, ',', '.');
        $sign = $cents < 0 ? '−' : '';

        return $sign.($currency === 'USD' ? 'US$' : '₡').$number;
    }

    /** «por mes» o «por año». */
    public static function cycle(string $cycle): string
    {
        return $cycle === 'annual' ? 'por año' : 'por mes';
    }
}
