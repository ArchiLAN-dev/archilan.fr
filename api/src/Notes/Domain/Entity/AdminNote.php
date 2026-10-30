<?php

declare(strict_types=1);

namespace App\Notes\Domain\Entity;

use App\Notes\Domain\Enum\FollowUpKind;
use App\Notes\Domain\Enum\NoteStatus;
use App\Notes\Domain\Enum\NoteTargetType;
use Doctrine\ORM\Mapping as ORM;

/**
 * An admin note on a target (story 46.1): something an admin noticed and wrote down so it is not lost. Only admins
 * ever read it. It stays open until settled, can be reopened, and is never deleted. Editing keeps the original
 * author and returns the previous text as a revision.
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_note')]
#[ORM\Index(name: 'idx_admin_note_target', columns: ['target_type', 'target_id', 'status'])]
final class AdminNote
{
    public const int MAX_BODY = 2000;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'target_type', type: 'string', length: 32, enumType: NoteTargetType::class)]
        private NoteTargetType $targetType,
        #[ORM\Column(name: 'target_id', type: 'string', length: 64)]
        private string $targetId,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(type: 'string', length: 16, enumType: NoteStatus::class)]
        private NoteStatus $status,
        #[ORM\Column(name: 'author_id', type: 'string', length: 32)]
        private string $authorId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /** @throws \DomainException note_body_invalid */
    public static function write(string $id, NoteTargetType $targetType, string $targetId, string $body, string $authorId, \DateTimeImmutable $now): self
    {
        return new self($id, $targetType, $targetId, self::validBody($body), NoteStatus::Open, $authorId, $now, $now);
    }

    /**
     * The author stays the original one. An unchanged text (once trimmed) is no edit.
     *
     * @return NoteRevision|null the previous text, or null when nothing changed
     *
     * @throws \DomainException note_body_invalid
     */
    public function edit(string $revisionId, string $body, string $editorId, \DateTimeImmutable $now): ?NoteRevision
    {
        $body = self::validBody($body);
        if ($body === $this->body) {
            return null;
        }
        $revision = NoteRevision::record($revisionId, $this->id, $this->body, $editorId, $now);
        $this->body = $body;
        $this->updatedAt = $now;

        return $revision;
    }

    /** @throws \DomainException note_follow_up_body_invalid */
    public function followUp(string $followUpId, string $body, string $authorId, \DateTimeImmutable $now): NoteFollowUp
    {
        $followUp = NoteFollowUp::record($followUpId, $this->id, FollowUpKind::Comment, $body, $authorId, $now);
        $this->updatedAt = $now;

        return $followUp;
    }

    /** @throws \DomainException note_already_settled, note_follow_up_body_invalid */
    public function settle(string $followUpId, string $body, string $authorId, \DateTimeImmutable $now): NoteFollowUp
    {
        if (NoteStatus::Settled === $this->status) {
            throw new \DomainException('note_already_settled');
        }

        return $this->turn(NoteStatus::Settled, FollowUpKind::Settled, $followUpId, $body, $authorId, $now);
    }

    /** @throws \DomainException note_already_open, note_follow_up_body_invalid */
    public function reopen(string $followUpId, string $body, string $authorId, \DateTimeImmutable $now): NoteFollowUp
    {
        if (NoteStatus::Open === $this->status) {
            throw new \DomainException('note_already_open');
        }

        return $this->turn(NoteStatus::Open, FollowUpKind::Reopened, $followUpId, $body, $authorId, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTargetType(): NoteTargetType
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): NoteStatus
    {
        return $this->status;
    }

    public function getAuthorId(): string
    {
        return $this->authorId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function turn(NoteStatus $status, FollowUpKind $kind, string $followUpId, string $body, string $authorId, \DateTimeImmutable $now): NoteFollowUp
    {
        $followUp = NoteFollowUp::record($followUpId, $this->id, $kind, $body, $authorId, $now);
        $this->status = $status;
        $this->updatedAt = $now;

        return $followUp;
    }

    private static function validBody(string $body): string
    {
        $body = trim($body);
        if ('' === $body || mb_strlen($body) > self::MAX_BODY) {
            throw new \DomainException('note_body_invalid');
        }

        return $body;
    }
}
