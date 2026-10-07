<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Exception\InvalidPelleMovementException;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the pelles ledger (story 41.1). The ledger is append-only: a balance is the sum of the lines,
 * a correction is an inverse line. The optional unique key makes a credit idempotent (a happening or an
 * achievement never pays twice).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pelle_movement')]
#[ORM\Index(name: 'idx_pelle_movement_user', columns: ['user_id', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_pelle_movement_key', columns: ['unique_key'])]
final class PelleMovement
{
    public const int LABEL_MAX_LENGTH = 200;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(type: 'integer')]
        private int $amount,
        #[ORM\Column(type: 'string', length: 10, enumType: PelleKind::class)]
        private PelleKind $kind,
        #[ORM\Column(name: 'event_id', type: 'string', length: 32, nullable: true)]
        private ?string $eventId,
        #[ORM\Column(type: 'string', length: 40, enumType: PelleReason::class)]
        private PelleReason $reason,
        #[ORM\Column(type: 'string', length: self::LABEL_MAX_LENGTH)]
        private string $label,
        #[ORM\Column(name: 'author_id', type: 'string', length: 32, nullable: true)]
        private ?string $authorId,
        #[ORM\Column(name: 'unique_key', type: 'string', length: 120, nullable: true)]
        private ?string $uniqueKey,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function record(
        string $userId,
        int $amount,
        PelleKind $kind,
        ?string $eventId,
        PelleReason $reason,
        string $label,
        ?string $authorId,
        ?string $uniqueKey,
        \DateTimeImmutable $now,
    ): self {
        $label = trim($label);
        if (0 === $amount) {
            throw new InvalidPelleMovementException('pelle_amount_zero');
        }
        if ((PelleKind::Event === $kind) !== (null !== $eventId)) {
            throw new InvalidPelleMovementException('pelle_event_mismatch');
        }
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new InvalidPelleMovementException('pelle_label_invalid');
        }

        return new self(bin2hex(random_bytes(16)), $userId, $amount, $kind, $eventId, $reason, $label, $authorId, $uniqueKey, $now);
    }

    public function isSpend(): bool
    {
        return $this->amount < 0;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getKind(): PelleKind
    {
        return $this->kind;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function getReason(): PelleReason
    {
        return $this->reason;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    public function getUniqueKey(): ?string
    {
        return $this->uniqueKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
