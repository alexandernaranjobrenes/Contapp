<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Conti\Support\ContiContext;

/**
 * Lo que Conti puede guardar, por clave. Agregar una es escribir su clase
 * (ContiAction) y sumarla acá.
 */
final class ContiActionCatalog
{
    private const ACTIONS = [
        CreateJournalEntryAction::class,
        CreateBusinessPartnerAction::class,
        UpdateBusinessPartnerAction::class,
        ApplyPaymentAction::class,
        RecordExchangeRateAction::class,
        CreateCostCenterAction::class,
        CreateAccountAction::class,
        UpdatePricesAction::class,
        CreatePurchaseOrderAction::class,
        AddEmployeeNoteAction::class,
        AddPayrollInputAction::class,
        RecordVacationAction::class,
    ];

    /** @return array<string, ContiAction> */
    public static function all(): array
    {
        $actions = [];

        foreach (self::ACTIONS as $class) {
            $action = app($class);
            $actions[$action->key()] = $action;
        }

        return $actions;
    }

    public static function find(string $key): ?ContiAction
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<ContiAction> las que esta persona puede pedir (Lectura y escritura en su pantalla) */
    public static function availableTo(ContiContext $context): array
    {
        return array_values(array_filter(self::all(), fn (ContiAction $a) => $context->allows($a->screen(), 'read_write')));
    }
}
