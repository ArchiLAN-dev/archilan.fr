<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The blind test of one event (story 41.40): whether its app may credit pelles, how many a found source is worth and
 * how many one member may earn from it over the event. An event with no row has the blind test off.
 */
#[ORM\Entity]
#[ORM\Table(name: 'blind_test_setting')]
final class BlindTestSetting
{
    public const int DEFAULT_PELLES_PER_SOURCE = 1;
    public const int DEFAULT_CAP_PER_PLAYER = 50;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Column(type: 'boolean')]
        private bool $enabled,
        #[ORM\Column(name: 'pelles_per_source', type: 'integer')]
        private int $pellesPerSource,
        #[ORM\Column(name: 'cap_per_player', type: 'integer')]
        private int $capPerPlayer,
    ) {
    }

    public static function off(string $eventId): self
    {
        return new self($eventId, false, self::DEFAULT_PELLES_PER_SOURCE, self::DEFAULT_CAP_PER_PLAYER);
    }

    public function change(bool $enabled, int $pellesPerSource, int $capPerPlayer): void
    {
        $this->enabled = $enabled;
        $this->pellesPerSource = $pellesPerSource;
        $this->capPerPlayer = $capPerPlayer;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getPellesPerSource(): int
    {
        return $this->pellesPerSource;
    }

    public function getCapPerPlayer(): int
    {
        return $this->capPerPlayer;
    }
}
