<?php

declare(strict_types=1);

namespace App\Notes\Domain\Repository;

use App\Notes\Domain\Entity\AdminNote;
use App\Notes\Domain\Entity\NoteFollowUp;
use App\Notes\Domain\Entity\NoteRevision;

interface AdminNoteRepositoryInterface
{
    public function find(string $id): ?AdminNote;

    /** The note and what came with the change (a follow-up or a revision), in one flush. */
    public function save(AdminNote $note, NoteFollowUp|NoteRevision|null $record = null): void;
}
