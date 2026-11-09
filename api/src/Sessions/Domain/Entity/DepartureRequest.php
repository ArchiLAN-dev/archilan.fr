<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Story 52.6: a player left - they said so (« Je pars ») or their declared departure passed with their slot offline.
 * Open until a staff member (the owner in a private run) releases their world or dismisses it (« il revient »).
 * The player never releases themselves. One open request per slot: the partial unique index.
 */
#[ORM\Entity]
#[ORM\Table(name: 'departure_request')]
#[ORM\UniqueConstraint(name: 'uniq_departure_request_open', columns: ['session_id', 'slot_name'], options: ['where' => "status = 'open'"])]
#[ORM\Index(name: 'idx_departure_request_session', columns: ['session_id', 'status'])]
final class DepartureRequest
{
    public const string STATUS_OPEN = 'open';
    public const string STATUS_RELEASED = 'released';
    public const string STATUS_DISMISSED = 'dismissed';

    public const string ORIGIN_PLAYER = 'player';
    public const string ORIGIN_SCHEDULE = 'schedule';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $id,
        #[ORM\Column(name: 'session_id', type: Types::STRING, length: 64)]
        private string $sessionId,
        #[ORM\Column(name: 'slot_index', type: Types::INTEGER)]
        private int $slotIndex,
        #[ORM\Column(name: 'slot_name', type: Types::STRING, length: 100)]
        private string $slotName,
        /** `player` (« Je pars ») or `schedule` (the declared departure passed). */
        #[ORM\Column(type: Types::STRING, length: 10)]
        private string $origin,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::STRING, length: 10)]
        private string $status = self::STATUS_OPEN,
        #[ORM\Column(name: 'closed_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $closedAt = null,
        #[ORM\Column(name: 'closed_by', type: Types::STRING, length: 32, nullable: true)]
        private ?string $closedBy = null,
    ) {
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    public function markReleased(string $by, \DateTimeImmutable $now): void
    {
        $this->close(self::STATUS_RELEASED, $by, $now);
    }

    public function dismiss(string $by, \DateTimeImmutable $now): void
    {
        $this->close(self::STATUS_DISMISSED, $by, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function getSlotName(): string
    {
        return $this->slotName;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosedBy(): ?string
    {
        return $this->closedBy;
    }

    private function close(string $status, string $by, \DateTimeImmutable $now): void
    {
        if (!$this->isOpen()) {
            throw new \DomainException('departure_request_closed');
        }
        $this->status = $status;
        $this->closedBy = $by;
        $this->closedAt = $now;
    }
}
