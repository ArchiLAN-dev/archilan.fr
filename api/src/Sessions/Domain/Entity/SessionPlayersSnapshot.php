<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The last players state the bridge pushed for a session (story 17.21): one row per session,
 * overwritten on every push. Serves the Progression tab when the bridge is unreachable - an idle
 * or stopped session keeps showing the last known checks/goal state instead of going blank.
 * The payload is the bridge's `/state` shape verbatim (slots keyed by number, snake_case).
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_players_snapshot')]
final class SessionPlayersSnapshot
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'session_id', type: Types::STRING, length: 64)]
        private string $sessionId,

        /** @var array<array-key, mixed> */
        #[ORM\Column(type: Types::JSON)]
        private array $payload,

        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /** @return array<array-key, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @param array<array-key, mixed> $payload */
    public function refresh(array $payload, \DateTimeImmutable $now): void
    {
        $this->payload = $payload;
        $this->updatedAt = $now;
    }
}
