<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A community-submitted install tutorial for a game - either an existing game ({@see $gameId}) or a
 * game not yet in the catalog ({@see $proposedGameName}); exactly one is set. Starts `pending`; an
 * admin approves (applies the steps) or rejects it in story 31.7.
 */
#[ORM\Entity]
#[ORM\Table(name: 'game_tutorial_contribution')]
final class GameTutorialContribution
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @param list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}> $steps
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'author_id', type: 'string', length: 32)]
        private string $authorId,
        #[ORM\Column(name: 'game_id', type: 'string', length: 32, nullable: true)]
        private ?string $gameId,
        #[ORM\Column(name: 'proposed_game_name', type: 'string', length: 160, nullable: true)]
        private ?string $proposedGameName,
        #[ORM\Column(type: 'json')]
        private array $steps,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $message,
        #[ORM\Column(type: 'string', length: 16)]
        private string $status,
        #[ORM\Column(name: 'reviewed_by', type: 'string', length: 32, nullable: true)]
        private ?string $reviewedBy,
        #[ORM\Column(name: 'reviewed_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $reviewedAt,
        #[ORM\Column(name: 'rejection_reason', type: 'text', nullable: true)]
        private ?string $rejectionReason,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}> $steps
     */
    public static function submitForGame(string $id, string $authorId, string $gameId, array $steps, ?string $message, \DateTimeImmutable $now): self
    {
        return new self($id, $authorId, $gameId, null, $steps, self::cleanMessage($message), self::STATUS_PENDING, null, null, null, $now);
    }

    /**
     * @param list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}> $steps
     */
    public static function submitForProposedName(string $id, string $authorId, string $proposedGameName, array $steps, ?string $message, \DateTimeImmutable $now): self
    {
        return new self($id, $authorId, null, trim($proposedGameName), $steps, self::cleanMessage($message), self::STATUS_PENDING, null, null, null, $now);
    }

    public function approve(string $reviewerId, \DateTimeImmutable $now): void
    {
        $this->guardPending();
        $this->status = self::STATUS_APPROVED;
        $this->reviewedBy = $reviewerId;
        $this->reviewedAt = $now;
    }

    public function reject(string $reviewerId, string $reason, \DateTimeImmutable $now): void
    {
        $this->guardPending();
        $this->status = self::STATUS_REJECTED;
        $this->reviewedBy = $reviewerId;
        $this->reviewedAt = $now;
        $this->rejectionReason = trim($reason);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAuthorId(): string
    {
        return $this->authorId;
    }

    public function getGameId(): ?string
    {
        return $this->gameId;
    }

    public function getProposedGameName(): ?string
    {
        return $this->proposedGameName;
    }

    /**
     * @return list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getReviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function guardPending(): void
    {
        if (self::STATUS_PENDING !== $this->status) {
            throw new \DomainException('Contribution already moderated.');
        }
    }

    private static function cleanMessage(?string $message): ?string
    {
        if (null === $message) {
            return null;
        }
        $trimmed = trim($message);

        return '' === $trimmed ? null : $trimmed;
    }
}
