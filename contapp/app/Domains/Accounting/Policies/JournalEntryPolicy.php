<?php

namespace App\Domains\Accounting\Policies;

use App\Domains\Accounting\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    /**
     * Regla innegociable (CLAUDE.md): nada contabilizado se borra físicamente,
     * salvo por el super usuario, y esa facultad NO es delegable. Por eso vive
     * en código, no en document_type_permissions.can_delete: si viviera en esa
     * tabla, un super usuario podría otorgarla por error o mala fe vía UI.
     *
     * Un borrador (nunca contabilizado) sí puede borrarse por cualquier
     * usuario que tenga can_delete en document_type_permissions para ese tipo
     * de documento; esa verificación vive en el service/controller, no aquí.
     */
    public function delete(User $user, JournalEntry $journalEntry): bool
    {
        if ($journalEntry->status === 'draft') {
            return true;
        }

        return $user->isSuperAdmin();
    }
}
