<?php

declare(strict_types=1);

namespace App\Notes\Application\Query;

use App\Notes\Domain\Enum\NoteTargetType;

/**
 * Reads the admin notes (story 46.1). An author whose account is gone reads as a null display name.
 *
 * @phpstan-type Author array{id: string, displayName: string|null}
 * @phpstan-type FollowUpView array{id: string, kind: string, body: string, author: Author, createdAt: string}
 * @phpstan-type NoteView array{id: string, body: string, status: string, author: Author, createdAt: string, updatedAt: string, edited: bool, followUps: list<FollowUpView>}
 * @phpstan-type RevisionView array{previousBody: string, editor: Author, editedAt: string}
 */
interface AdminNoteQueryInterface
{
    /**
     * Open notes first, then settled ones; newest first in each group; follow-ups in chronological order.
     *
     * @return list<NoteView>
     */
    public function forTarget(NoteTargetType $type, string $targetId): array;

    /**
     * Newest first, or null when the note does not exist.
     *
     * @return list<RevisionView>|null
     */
    public function revisions(string $noteId): ?array;

    /**
     * Open notes per target, in one query. Targets without an open note are left out.
     *
     * @param list<string> $targetIds
     *
     * @return array<string, int>
     */
    public function openCounts(NoteTargetType $type, array $targetIds): array;
}
