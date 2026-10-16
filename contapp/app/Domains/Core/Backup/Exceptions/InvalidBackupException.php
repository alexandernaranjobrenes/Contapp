<?php

namespace App\Domains\Core\Backup\Exceptions;

use RuntimeException;

/** El archivo no se puede importar. El mensaje es para la persona: qué pasó. */
class InvalidBackupException extends RuntimeException {}
