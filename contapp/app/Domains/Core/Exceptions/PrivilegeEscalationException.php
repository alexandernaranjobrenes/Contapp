<?php

namespace App\Domains\Core\Exceptions;

/**
 * Regla no negociable (CLAUDE.md secc. 12): nadie delega un permiso o un
 * tipo de rol que él mismo no posee. Cada intento rechazado por
 * PermissionGrantService queda además en audit_logs antes de lanzar esto.
 */
class PrivilegeEscalationException extends \RuntimeException {}
