<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\PriceOverrideAuthorization;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Services\PriceResolver;
use App\Models\User;

/**
 * El precio de la lista se respeta; apartarse de él requiere que lo libere un
 * administrador o el superusuario.
 *
 * ── Qué se compara: el precio NETO, no el unitario ───────────────────────
 *
 * La línea tiene precio unitario Y descuento. Controlar solo el unitario
 * dejaría el control abierto de par en par: bajar el precio por la vía del
 * descuento no tocaría ninguna validación. Lo que se compara es lo que el
 * cliente termina pagando por unidad:
 *
 *     neto = precio unitario − (descuento / cantidad)
 *
 * ── Qué NO controla ──────────────────────────────────────────────────────
 *
 * **Un artículo sin precio en la lista no se controla.** No hay de qué
 * apartarse. Exigir autorización ahí obligaría a tener el catálogo entero
 * con precio antes de poder facturar, y convertiría un control en un
 * bloqueo — el sistema dejaría de servir para vender.
 *
 * **Una línea sin artículo tampoco** (descripción libre, un servicio
 * puntual): no tiene lista contra la cual compararse.
 *
 * **Se controlan las dos direcciones.** Facturar por debajo de la lista es
 * el riesgo obvio; facturar por encima también es un error contra un precio
 * pactado, y lo descubre el cliente. El registro guarda el signo.
 */
class PriceOverrideGuard
{
    /**
     * Tolerancia en la comparación. Las listas guardan 5 decimales y la
     * pantalla manda números tecleados: sin margen, un 1000.00000 contra un
     * 1000 dispararía autorizaciones fantasma.
     */
    private const SCALE = 5;

    public function __construct(private readonly PriceResolver $resolver) {}

    /**
     * Quién puede liberar un cambio de precio: el superusuario de la
     * licencia y los roles de tipo admin de la compañía. La figura 'user'
     * —el vendedor— no.
     */
    public function canAuthorize(User $user, int $companyId): bool
    {
        return $user->isSuperAdmin($companyId) || $user->roleTypeFor($companyId) === 'admin';
    }

    /**
     * Las líneas que se apartan de la lista, con de cuánto es el desvío.
     * Vacío = la factura respeta los precios y no necesita autorización.
     *
     * @param  array<int, array<string, mixed>>  $lines  tal como llegan del formulario
     * @return array<int, array<string, mixed>>  indexado por el número de línea (base 0)
     */
    public function deviations(
        Company $company,
        ?BusinessPartner $customer,
        string $date,
        ?int $currencyId,
        array $lines,
        ?int $salesOrderId = null,
    ): array {
        $map = $this->resolver->priceMap($company, $customer, $date, $currencyId);

        // Sin lista aplicable no hay nada que respetar: es exactamente el
        // estado previo a que existieran las listas, y ahí todos los precios
        // se digitaban.
        if ($map['prices'] === []) {
            return [];
        }

        // Lo que el pedido ya trae autorizado. Es la pieza que evita el
        // doble trámite: el precio se pactó y se firmó al tomar el pedido, y
        // la factura que lo cumple no vuelve a pedir la misma firma.
        $alreadyAuthorized = $this->authorizedPricesOf($salesOrderId);

        $deviations = [];

        foreach ($lines as $index => $line) {
            $itemId = isset($line['item_id']) ? (int) $line['item_id'] : null;

            if ($itemId === null || ! isset($map['prices'][$itemId])) {
                continue;
            }

            $listPrice = $this->scale((string) $map['prices'][$itemId]);
            $net = $this->netUnitPrice($line);

            // Facturar exactamente el precio que el pedido autorizó no es un
            // desvío nuevo. Cambiarlo otra vez SÍ lo es, y cae por el
            // camino normal de abajo.
            if ($net !== null
                && isset($alreadyAuthorized[$itemId])
                && bccomp($net, $alreadyAuthorized[$itemId], self::SCALE) === 0) {
                continue;
            }

            if ($net === null || bccomp($net, $listPrice, self::SCALE) === 0) {
                continue;
            }

            $deviations[$index] = [
                'line_index' => $index,
                'item_id' => $itemId,
                'item_code' => $line['item_code'] ?? null,
                'description' => $line['description'] ?? null,
                'price_list_id' => $map['list']?->id,
                'price_list_code' => $map['list']?->code,
                'list_unit_price' => $listPrice,
                'invoiced_unit_price' => $net,
                'difference' => bcsub($net, $listPrice, self::SCALE),
            ];
        }

        return $deviations;
    }

    /**
     * Los precios que un pedido ya trae firmados, como item_id => precio.
     *
     * Si el mismo artículo se autorizó más de una vez en el pedido, vale el
     * último: es el precio que quedó pactado.
     *
     * @return array<int, string>
     */
    private function authorizedPricesOf(?int $salesOrderId): array
    {
        if ($salesOrderId === null) {
            return [];
        }

        return PriceOverrideAuthorization::where('sales_order_id', $salesOrderId)
            ->orderBy('id')
            ->get(['item_id', 'invoiced_unit_price'])
            ->filter(fn ($row) => $row->item_id !== null)
            ->mapWithKeys(fn ($row) => [
                (int) $row->item_id => $this->scale((string) $row->invoiced_unit_price),
            ])
            ->all();
    }

    /**
     * Lo que el cliente paga por unidad en esta línea. null si la cantidad
     * es cero o falta: ahí no hay precio unitario que comparar, y la
     * validación de la factura ya rechaza esa línea por otro lado.
     */
    private function netUnitPrice(array $line): ?string
    {
        // En un pedido el precio es opcional: null significa "todavía no se
        // pactó", no "vale cero". Tratarlo como cero haría que cada pedido
        // sin precio pidiera autorización, que es exactamente al revés de
        // lo que corresponde.
        if (! isset($line['unit_price']) || $line['unit_price'] === '') {
            return null;
        }

        $quantity = $this->scale((string) ($line['quantity'] ?? 0));

        if (bccomp($quantity, '0', self::SCALE) <= 0) {
            return null;
        }

        $unitPrice = $this->scale((string) ($line['unit_price'] ?? 0));
        $discount = $this->scale((string) ($line['discount_amount'] ?? 0));

        // El descuento de la línea es un monto total, no por unidad: hay que
        // prorratearlo para poder compararlo contra el precio de lista.
        return bcsub($unitPrice, bcdiv($discount, $quantity, self::SCALE), self::SCALE);
    }

    private function scale(string $value): string
    {
        return number_format((float) $value, self::SCALE, '.', '');
    }
}
