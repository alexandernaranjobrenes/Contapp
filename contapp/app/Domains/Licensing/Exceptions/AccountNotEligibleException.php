<?php

namespace App\Domains\Licensing\Exceptions;

use RuntimeException;

/**
 * La licencia está bien, pero la cuenta que quiere quedar como su dueña no
 * puede serlo. Aparte de InvalidLicenseException (que es un problema del
 * código) para que cada error se muestre en su campo.
 */
class AccountNotEligibleException extends RuntimeException
{
    public const EMAIL_TAKEN = 'email_taken';

    public const ALREADY_OWNER = 'already_owner';

    public const PASSWORD_NOT_CHOSEN = 'password_not_chosen';

    public const INACTIVE = 'inactive';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Alguien pidió crear una cuenta nueva con un correo que ya tiene una. */
    public static function emailTaken(): self
    {
        return new self(self::EMAIL_TAKEN, 'Ese correo ya tiene una cuenta en CONTAPP. Elegí «Ya tengo una cuenta» para activar la licencia con ella.');
    }

    public static function alreadyOwner(): self
    {
        return new self(self::ALREADY_OWNER, 'Esta cuenta ya es dueña de una licencia, y cada cuenta puede tener una sola. Si necesitás más compañías, pedí ampliar el cupo de la que ya tenés.');
    }

    public static function passwordNotChosen(): self
    {
        return new self(self::PASSWORD_NOT_CHOSEN, 'Esta cuenta la creó otra persona y todavía no confirmaste que el correo es tuyo. Antes de activar una licencia, elegí tu contraseña con «¿Olvidaste tu contraseña?».');
    }

    public static function inactive(): self
    {
        return new self(self::INACTIVE, 'Esta cuenta está inactiva y no puede activar una licencia.');
    }
}
