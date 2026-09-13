<?php

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\FiscalPeriod;
use App\Models\User;

/**
 * Reabrir un período Cerrado es la contraparte de borrar un asiento
 * contabilizado: reservado al super usuario y no delegable (ver
 * docs/propuesta-contapp.md §4.4 y la nota pendiente en
 * docs/decisiones.md 2026-08-05 sobre PeriodCloseService). Cerrar,
 * bloquear y desbloquear SÍ son delegables vía document_type_permissions
 * a futuro; por ahora cualquier usuario autenticado con acceso a la
 * compañía puede hacerlo, igual que el resto de las acciones de este módulo.
 */
class FiscalPeriodPolicy
{
    public function reopen(User $user, FiscalPeriod $period): bool
    {
        return $user->isSuperAdmin();
    }
}
