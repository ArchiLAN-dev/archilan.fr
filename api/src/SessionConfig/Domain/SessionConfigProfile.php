<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persisted config profile for a session type. The rich config lives as a JSON blob;
 * {@see SessionConfig::fromArray()} / toArray() are the single mapping. One row per type.
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_config_profiles')]
final class SessionConfigProfile
{
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $config;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'session_type', type: Types::STRING, length: 16)]
        private string $sessionType,
        SessionConfig $config,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
        $this->config = $config->toArray();
    }

    public function update(SessionConfig $config, \DateTimeImmutable $now): void
    {
        $this->config = $config->toArray();
        $this->updatedAt = $now;
    }

    public function type(): SessionType
    {
        return SessionType::fromString($this->sessionType);
    }

    public function toSessionConfig(): SessionConfig
    {
        return SessionConfig::fromArray($this->config);
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
