<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Minimapperz link of one event (story 49.1): the event's slug and organiser API key there, and what a game
 * played and a game won are worth in event pelles, up to a cap per member. An event with no row has it off.
 *
 * The key is stored as is: it only reads the scores of one event and is revoked on Minimapperz's side (epic 49).
 */
#[ORM\Entity]
#[ORM\Table(name: 'minimapperz_setting')]
final class MinimapperzSetting
{
    public const int DEFAULT_PELLES_PER_GAME = 1;
    public const int DEFAULT_PELLES_PER_WIN = 2;
    public const int DEFAULT_CAP_PER_PLAYER = 50;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Column(type: 'boolean')]
        private bool $enabled,
        #[ORM\Column(type: 'string', length: 100, nullable: true)]
        private ?string $slug,
        #[ORM\Column(name: 'api_key', type: 'string', length: 200, nullable: true)]
        private ?string $apiKey,
        #[ORM\Column(name: 'pelles_per_game', type: 'integer')]
        private int $pellesPerGame,
        #[ORM\Column(name: 'pelles_per_win', type: 'integer')]
        private int $pellesPerWin,
        #[ORM\Column(name: 'cap_per_player', type: 'integer')]
        private int $capPerPlayer,
    ) {
    }

    public static function off(string $eventId): self
    {
        return new self($eventId, false, null, null, self::DEFAULT_PELLES_PER_GAME, self::DEFAULT_PELLES_PER_WIN, self::DEFAULT_CAP_PER_PLAYER);
    }

    public function change(bool $enabled, ?string $slug, int $pellesPerGame, int $pellesPerWin, int $capPerPlayer): void
    {
        $this->enabled = $enabled;
        $this->slug = $slug;
        $this->pellesPerGame = $pellesPerGame;
        $this->pellesPerWin = $pellesPerWin;
        $this->capPerPlayer = $capPerPlayer;
    }

    public function replaceApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function clearApiKey(): void
    {
        $this->apiKey = null;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function getPellesPerGame(): int
    {
        return $this->pellesPerGame;
    }

    public function getPellesPerWin(): int
    {
        return $this->pellesPerWin;
    }

    public function getCapPerPlayer(): int
    {
        return $this->capPerPlayer;
    }
}
