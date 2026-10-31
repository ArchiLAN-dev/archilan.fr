<?php

declare(strict_types=1);

namespace App\Notes\Domain\Entity;

use App\Notes\Domain\Enum\FollowUpKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line in a note's timeline (story 46.1): a comment, or the note being settled or reopened. A comment needs a
 * text; a change of state may come without one. Never edited: a follow-up is corrected by another one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_note_follow_up')]
#[ORM\Index(name: 'idx_admin_note_follow_up_note', columns: ['note_id', 'created_at'])]
final class NoteFollowUp
{
    public const int MAX_BODY = 2000;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'note_id', type: 'string', length: 32)]
        private string $noteId,
        #[ORM\Column(type: 'string', length: 16, enumType: FollowUpKind::class)]
        private FollowUpKind $kind,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(name: 'author_id', type: 'string', length: 32)]
        private string $authorId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /** @throws \DomainException note_follow_up_body_invalid */
    public static function record(string $id, string $noteId, FollowUpKind $kind, string $body, string $authorId, \DateTimeImmutable $now): self
    {
        $body = trim($body);
        if (mb_strlen($body) > self::MAX_BODY || (FollowUpKind::Comment === $kind && '' === $body)) {
            throw new \DomainException('note_follow_up_body_invalid');
        }

        return new self($id, $noteId, $kind, $body, $authorId, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNoteId(): string
    {
        return $this->noteId;
    }

    public function getKind(): FollowUpKind
    {
        return $this->kind;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getAuthorId(): string
    {
        return $this->authorId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
