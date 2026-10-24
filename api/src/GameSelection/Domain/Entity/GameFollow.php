<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member follows a game (story 38.22): they are told each time the site puts a new apworld in place for it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'game_follow')]
#[ORM\UniqueConstraint(name: 'uniq_game_follow', columns: ['user_id', 'game_id'])]
#[ORM\Index(name: 'idx_game_follow_game', columns: ['game_id'])]
final class GameFollow
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(string $userId, string $gameId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $gameId, $now);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
