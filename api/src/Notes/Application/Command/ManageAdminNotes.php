<?php

declare(strict_types=1);

namespace App\Notes\Application\Command;

use App\Notes\Application\Port\NoteTargetInterface;
use App\Notes\Application\Support\NoteTargetTypes;
use App\Notes\Domain\Entity\AdminNote;
use App\Notes\Domain\Repository\AdminNoteRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Writes, edits, follows up, settles and reopens the admin notes (story 46.1). Each call is one unit of work: the
 * note and its follow-up or revision are saved together. Nothing here deletes a note.
 */
final readonly class ManageAdminNotes
{
    private const array MESSAGES = [
        'note_body_invalid' => 'Une note de 1 à 2000 caractères.',
        'note_follow_up_body_invalid' => 'Un suivi de 2000 caractères au plus, et non vide pour un commentaire.',
    ];

    private const array CONFLICTS = [
        'note_already_settled' => 'Cette note est déjà réglée.',
        'note_already_open' => 'Cette note est déjà ouverte.',
    ];

    public function __construct(
        private AdminNoteRepositoryInterface $notes,
        private NoteTargetInterface $targets,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the id of the new note
     *
     * @throws ValidationException when the target type or the text is invalid
     * @throws NotFoundException   when the target does not exist
     */
    public function write(string $targetType, string $targetId, string $body, string $authorId): string
    {
        $type = NoteTargetTypes::parse($targetType);
        if (!$this->targets->exists($type, $targetId)) {
            throw new NotFoundException('Cible de la note introuvable.', 'note_target_not_found');
        }
        $now = $this->clock->now();
        try {
            $note = AdminNote::write(self::newId($now), $type, $targetId, $body, $authorId, $now);
        } catch (\DomainException $e) {
            throw $this->failure($e);
        }
        $this->notes->save($note);

        return $note->getId();
    }

    /**
     * @throws NotFoundException   when the note does not exist
     * @throws ValidationException when the text is invalid
     */
    public function edit(string $noteId, string $body, string $editorId): void
    {
        $note = $this->get($noteId);
        $now = $this->clock->now();
        try {
            $revision = $note->edit(self::newId($now), $body, $editorId, $now);
        } catch (\DomainException $e) {
            throw $this->failure($e);
        }
        if (null !== $revision) {
            $this->notes->save($note, $revision);
        }
    }

    /**
     * @return string the id of the new follow-up
     *
     * @throws NotFoundException   when the note does not exist
     * @throws ValidationException when the text is invalid
     */
    public function followUp(string $noteId, string $body, string $authorId): string
    {
        $note = $this->get($noteId);
        $now = $this->clock->now();
        try {
            $followUp = $note->followUp(self::newId($now), $body, $authorId, $now);
        } catch (\DomainException $e) {
            throw $this->failure($e);
        }
        $this->notes->save($note, $followUp);

        return $followUp->getId();
    }

    /**
     * @throws NotFoundException   when the note does not exist
     * @throws ConflictException   when the note is already settled
     * @throws ValidationException when the text is invalid
     */
    public function settle(string $noteId, string $body, string $authorId): void
    {
        $note = $this->get($noteId);
        $now = $this->clock->now();
        try {
            $followUp = $note->settle(self::newId($now), $body, $authorId, $now);
        } catch (\DomainException $e) {
            throw $this->failure($e);
        }
        $this->notes->save($note, $followUp);
    }

    /**
     * @throws NotFoundException   when the note does not exist
     * @throws ConflictException   when the note is already open
     * @throws ValidationException when the text is invalid
     */
    public function reopen(string $noteId, string $body, string $authorId): void
    {
        $note = $this->get($noteId);
        $now = $this->clock->now();
        try {
            $followUp = $note->reopen(self::newId($now), $body, $authorId, $now);
        } catch (\DomainException $e) {
            throw $this->failure($e);
        }
        $this->notes->save($note, $followUp);
    }

    private function get(string $noteId): AdminNote
    {
        return $this->notes->find($noteId) ?? throw new NotFoundException('Note introuvable.', 'note_not_found');
    }

    private function failure(\DomainException $e): ValidationException|ConflictException
    {
        $code = $e->getMessage();
        if (isset(self::CONFLICTS[$code])) {
            return new ConflictException(self::CONFLICTS[$code], $code);
        }

        return new ValidationException(self::MESSAGES[$code] ?? 'Note invalide.', [], $code);
    }

    /**
     * 32 hex characters that sort in time: the clock's microseconds, then randomness. Dates are stored to the
     * second, so the id is what keeps a timeline in order when two writes land in the same second.
     */
    private static function newId(\DateTimeImmutable $now): string
    {
        return sprintf('%014x', (int) $now->format('Uu')).bin2hex(random_bytes(9));
    }
}
