<?php

declare(strict_types=1);

namespace App\Notes\Application\Query;

use App\Notes\Application\Support\NoteTargetTypes;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;

/**
 * The admin notes as the admin screens read them (story 46.1): the target type comes as sent by the client and is
 * checked here.
 *
 * @phpstan-import-type NoteView from AdminNoteQueryInterface
 * @phpstan-import-type RevisionView from AdminNoteQueryInterface
 */
final readonly class AdminNoteQuery
{
    /** A registrations list is far below this; past it the caller is asking for something else. */
    public const int MAX_TARGET_IDS = 500;

    public function __construct(private AdminNoteQueryInterface $query)
    {
    }

    /**
     * @return list<NoteView>
     *
     * @throws ValidationException when the target type is unknown
     */
    public function forTarget(string $targetType, string $targetId): array
    {
        return $this->query->forTarget(NoteTargetTypes::parse($targetType), $targetId);
    }

    /**
     * @return list<RevisionView>
     *
     * @throws NotFoundException when the note does not exist
     */
    public function revisions(string $noteId): array
    {
        return $this->query->revisions($noteId) ?? throw new NotFoundException('Note introuvable.', 'note_not_found');
    }

    /**
     * @param list<string> $targetIds
     *
     * @return array<string, int>
     *
     * @throws ValidationException when the target type is unknown or the list too long
     */
    public function openCounts(string $targetType, array $targetIds): array
    {
        $type = NoteTargetTypes::parse($targetType);
        if (count($targetIds) > self::MAX_TARGET_IDS) {
            throw new ValidationException(sprintf('%d cibles au plus.', self::MAX_TARGET_IDS), [], 'note_target_ids_invalid');
        }

        return $this->query->openCounts($type, array_values(array_unique($targetIds)));
    }
}
