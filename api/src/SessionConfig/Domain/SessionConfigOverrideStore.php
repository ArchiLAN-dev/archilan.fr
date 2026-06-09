<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persisted per-session override (one row per external session id). Stores the override
 * as a JSON blob; {@see SessionConfigOverride::fromArray()} / toArray() are the mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_config_overrides')]
final class SessionConfigOverrideStore
{
    /** @var array<string, mixed> */
    #[ORM\Column(name: 'override_config', type: Types::JSON)]
    private array $override;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'session_id', type: Types::STRING, length: 191)]
        private string $sessionId,
        SessionConfigOverride $override,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
        $this->override = $override->toArray();
    }

    public function update(SessionConfigOverride $override, \DateTimeImmutable $now): void
    {
        $this->override = $override->toArray();
        $this->updatedAt = $now;
    }

    public function toOverride(): SessionConfigOverride
    {
        return SessionConfigOverride::fromArray($this->override);
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
