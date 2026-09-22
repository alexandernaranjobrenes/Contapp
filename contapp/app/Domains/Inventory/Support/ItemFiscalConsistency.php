<?php

namespace App\Domains\Inventory\Support;

use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Tax\Models\TaxRate;

/**
 * El artículo lleva DOS datos de impuesto que tienen que decir lo mismo:
 * `tax_rate_id`, el indicador interno con el que se CONTABILIZA, y
 * `iva_rate_code`, el código con el que la factura se DECLARA ante Hacienda.
 * Si no coinciden, el XML declara un porcentaje y el asiento registra otro —
 * una diferencia que no salta a la vista en ninguna pantalla y que aparece
 * recién en una fiscalización.
 *
 * Vive acá y no en el controlador porque hay dos caminos para crear un
 * artículo —la ficha y la carga masiva— y una regla que se aplica en uno solo
 * es peor que no tenerla: da la impresión de estar protegido mientras el otro
 * camino la evade en silencio.
 *
 * ── Por qué se compara por porcentaje y no por código ────────────────────
 *
 * No hay correspondencia uno a uno. El catálogo de Hacienda tiene cuatro
 * códigos que valen 0% (con derecho a crédito, transitorio, exento y sin
 * derecho a crédito) y dos que valen 4%. Cuál de ellos corresponde es una
 * decisión fiscal del usuario que el sistema no tiene con qué tomar; lo único
 * que puede exigir es que el número cuadre.
 */
class ItemFiscalConsistency
{
    /**
     * @return string|null  el mensaje de error, o null si no hay conflicto
     */
    public static function error(?int $taxRateId, ?string $ivaRateCode): ?string
    {
        if ($taxRateId === null || $ivaRateCode === null) {
            return null;
        }

        $rate = TaxRate::find($taxRateId);

        if ($rate === null) {
            return null;
        }

        $fiscal = FiscalCatalogs::IVA_RATES[$ivaRateCode]['percentage'] ?? null;

        if ($fiscal === null || bccomp((string) $rate->percentage, $fiscal, 2) === 0) {
            return null;
        }

        return "El indicador de impuesto {$rate->code} es del {$rate->percentage}%, pero la tarifa de Hacienda ".
            "elegida ({$ivaRateCode}) es del {$fiscal}%. La factura declararía un porcentaje distinto al que se contabiliza.";
    }
}
