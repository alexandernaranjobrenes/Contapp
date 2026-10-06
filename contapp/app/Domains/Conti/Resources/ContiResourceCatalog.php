<?php

namespace App\Domains\Conti\Resources;

use App\Domains\Conti\Support\ContiContext;

/**
 * Todo lo que Conti puede consultar, por clave (GET /api/conti/datos/{clave}).
 *
 * Agregar un conjunto es definirlo en el archivo de su módulo: con qué
 * pantallas se ve, su consulta y sus campos. Un test revisa que cada uno
 * pida al menos una pantalla que exista en el menú y que ninguno devuelva
 * campos sensibles.
 */
final class ContiResourceCatalog
{
    /** @var array<string, ContiResource>|null */
    private static ?array $resources = null;

    /** @return array<string, ContiResource> */
    public static function all(): array
    {
        if (self::$resources !== null) {
            return self::$resources;
        }

        $resources = [];

        foreach ([
            ...AccountingResources::all(),
            ...PartnerResources::all(),
            ...InventoryResources::all(),
            ...BillingResources::all(),
            ...PayrollResources::all(),
        ] as $resource) {
            $resources[$resource->key] = $resource;
        }

        return self::$resources = $resources;
    }

    public static function find(string $key): ?ContiResource
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<ContiResource> los que esta persona puede consultar */
    public static function availableTo(ContiContext $context): array
    {
        return array_values(array_filter(
            self::all(),
            fn (ContiResource $r) => $r->screens === [] || $context->allowsAny($r->screens),
        ));
    }
}
