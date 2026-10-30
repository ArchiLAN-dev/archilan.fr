<?php

declare(strict_types=1);

namespace App\Notes\Application\Support;

use App\Notes\Domain\Enum\NoteTargetType;
use App\Shared\Application\Exception\ValidationException;

/** Reads a target type sent by a client (story 46.1): an unknown one is refused, never stored. */
final class NoteTargetTypes
{
    /** @throws ValidationException note_target_type_invalid */
    public static function parse(string $targetType): NoteTargetType
    {
        return NoteTargetType::tryFrom($targetType)
            ?? throw new ValidationException('Type de cible inconnu.', [], 'note_target_type_invalid');
    }
}
