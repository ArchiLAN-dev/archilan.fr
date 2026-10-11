<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Discord message telling one run listing (story 43.27): which message holds it, what it said last, and whether the
 * role was ever mentioned for this run. The row outlives the message: a listing taken down keeps its mention date, so
 * reopening the run does not ping the role again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personal_run_listing_post')]
final class RunListingPost
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'personal_run_id', type: 'string', length: 32)]
        private string $runId,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'message_id', type: 'string', length: 32, nullable: true)]
        private ?string $messageId = null,
        #[ORM\Column(name: 'fingerprint', type: 'string', length: 64, nullable: true)]
        private ?string $fingerprint = null,
        #[ORM\Column(name: 'mentioned_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $mentionedAt = null,
    ) {
    }

    public static function open(string $runId, \DateTimeImmutable $now): self
    {
        return new self($runId, $now);
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    /** Whether the message on Discord already says this. */
    public function says(string $fingerprint): bool
    {
        return null !== $this->messageId && $this->fingerprint === $fingerprint;
    }

    /** Whether a new message for this run may mention the role: never done before. */
    public function mayMention(): bool
    {
        return null === $this->mentionedAt;
    }

    public function posted(string $messageId, string $fingerprint, bool $mentioned, \DateTimeImmutable $now): void
    {
        $this->messageId = $messageId;
        $this->fingerprint = $fingerprint;
        if ($mentioned && null === $this->mentionedAt) {
            $this->mentionedAt = $now;
        }
        $this->updatedAt = $now;
    }

    /** The listing is no longer online and its message is gone. */
    public function withdrawn(\DateTimeImmutable $now): void
    {
        $this->messageId = null;
        $this->fingerprint = null;
        $this->updatedAt = $now;
    }
}
