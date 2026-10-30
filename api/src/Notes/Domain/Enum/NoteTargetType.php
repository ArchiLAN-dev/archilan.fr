<?php

declare(strict_types=1);

namespace App\Notes\Domain\Enum;

/**
 * What an admin note is written on (story 46.1). A closed list: a new target is a new case here, and the target
 * check must then handle it.
 */
enum NoteTargetType: string
{
    case Registration = 'registration';
}
