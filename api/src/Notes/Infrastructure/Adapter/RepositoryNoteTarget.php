<?php

declare(strict_types=1);

namespace App\Notes\Infrastructure\Adapter;

use App\Notes\Application\Port\NoteTargetInterface;
use App\Notes\Domain\Enum\NoteTargetType;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;

/** Checks a note's target in the context that owns it (story 46.1). A new target type must be handled here. */
final readonly class RepositoryNoteTarget implements NoteTargetInterface
{
    public function __construct(private RegistrationRepositoryInterface $registrations)
    {
    }

    public function exists(NoteTargetType $type, string $id): bool
    {
        return match ($type) {
            NoteTargetType::Registration => null !== $this->registrations->findById($id),
        };
    }
}
