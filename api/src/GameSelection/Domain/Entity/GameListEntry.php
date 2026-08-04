<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Entity;

use App\GameSelection\Domain\Enum\GameListKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * One game, on one of a player's lists, stored by ArchiLAN alone (story 28.13).
 *
 * The Steam coupling can only ever recognise games that exist on Steam and carry a `steamAppId`.
 * Most of this catalog does not: a GameCube or SNES title has no store id to match, so it could
 * never be marked as owned however many libraries a player couples. This is the manual answer, and
 * it is deliberately independent - nothing here is synced to, or overwritten by, Steam.
 *
 * The triple (player, game, kind) is the identity, so adding twice is idempotent by design rather
 * than by a check in the service - and the same game may sit on two lists without either fighting
 * the other.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_game_list')]
#[ORM\Index(name: 'idx_user_game_list_user_kind', columns: ['user_id', 'kind'])]
final class GameListEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Id]
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Id]
        #[ORM\Column(name: 'kind', type: 'string', length: 16, enumType: GameListKind::class)]
        private GameListKind $kind,
        #[ORM\Column(name: 'marked_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $markedAt,
    ) {
    }

    public static function add(string $userId, string $gameId, GameListKind $kind, \DateTimeImmutable $now): self
    {
        return new self($userId, $gameId, $kind, $now);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getKind(): GameListKind
    {
        return $this->kind;
    }

    public function getMarkedAt(): \DateTimeImmutable
    {
        return $this->markedAt;
    }
}
