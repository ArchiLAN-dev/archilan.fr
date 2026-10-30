<?php

declare(strict_types=1);

namespace App\Notes\Application\Port;

use App\Notes\Domain\Enum\NoteTargetType;

/** Whether the thing a note is about exists (story 46.1). Notes knows no business entity: the check lives behind this port. */
interface NoteTargetInterface
{
    public function exists(NoteTargetType $type, string $id): bool;
}
