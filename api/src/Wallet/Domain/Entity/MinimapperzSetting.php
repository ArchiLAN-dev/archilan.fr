<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Minimapperz link of one event (story 49.1): the event's slug and organiser API key there, and what a game
 * played and a game won are worth in event pelles, up to a cap per member. An event with no row has it off.
 *
 * The key is stored as is: it only reads the scores of one event and is revoked on Minimapperz's side (epic 49).
 *
 * Story 49.3: where the collection of the games stands - the end of the newest game read, and how the last pass went.
 */
#[ORM\Entity]
#[ORM\Table(name: 'minimapperz_setting')]
final class MinimapperzSetting
{
    public const int DEFAULT_PELLES_PER_GAME = 1;
    public const int DEFAULT_PELLES_PER_WIN = 2;
    public const int DEFAULT_CAP_PER_PLAYER = 50;
    public const string COLLECT_OK = 'ok';
    public const string COLLECT_UNAUTHORIZED = 'unauthorized';
    public const string COLLECT_UNREACHABLE = 'unreachable';

    #[ORM\Column(name: 'collected_until', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $collectedUntil = null;

    #[ORM\Column(name: 'last_collect_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCollectAt = null;

    #[ORM\Column(name: 'last_collect_status', type: 'string', length: 20, nullable: true)]
    private ?string $lastCollectStatus = null;

    #[ORM\Column(name: 'last_collect_games', type: 'integer', options: ['default' => 0])]
    private int $lastCollectGames = 0;

    #[ORM\Column(name: 'last_collect_credited', type: 'integer', options: ['default' => 0])]
    private int $lastCollectCredited = 0;

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

    /** A pass that read the games: the cursor moves on to the newest game seen. */
    public function recordCollection(\DateTimeImmutable $at, int $games, int $credited, ?\DateTimeImmutable $newestGame): void
    {
        $this->lastCollectAt = $at;
        $this->lastCollectStatus = self::COLLECT_OK;
        $this->lastCollectGames = $games;
        $this->lastCollectCredited = $credited;
        if (null !== $newestGame && (null === $this->collectedUntil || $newestGame > $this->collectedUntil)) {
            $this->collectedUntil = $newestGame;
        }
    }

    /** A pass Minimapperz could not answer: told to the admin, retried at the next pass, the cursor kept. */
    public function recordUnreachable(\DateTimeImmutable $at): void
    {
        $this->lastCollectAt = $at;
        $this->lastCollectStatus = self::COLLECT_UNREACHABLE;
    }

    /** Minimapperz refused the key: the link is switched off until an admin fixes it. */
    public function suspendForRefusedKey(\DateTimeImmutable $at): void
    {
        $this->enabled = false;
        $this->lastCollectAt = $at;
        $this->lastCollectStatus = self::COLLECT_UNAUTHORIZED;
    }

    public function getCollectedUntil(): ?\DateTimeImmutable
    {
        return $this->collectedUntil;
    }

    public function getLastCollectAt(): ?\DateTimeImmutable
    {
        return $this->lastCollectAt;
    }

    public function getLastCollectStatus(): ?string
    {
        return $this->lastCollectStatus;
    }

    public function getLastCollectGames(): int
    {
        return $this->lastCollectGames;
    }

    public function getLastCollectCredited(): int
    {
        return $this->lastCollectCredited;
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
