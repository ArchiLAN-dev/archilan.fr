<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The moment the Discord bans present at activation were all told to the staff (story 39.9). One row, or none:
 * until it exists, the ban sync only shows what it finds and applies nothing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_discord_ban_baseline')]
final class DiscordBanBaseline
{
    public const int SINGLETON = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'smallint')]
        private int $id,
        #[ORM\Column(name: 'taken_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $takenAt,
    ) {
    }

    public static function take(\DateTimeImmutable $now): self
    {
        return new self(self::SINGLETON, $now);
    }

    public function getTakenAt(): \DateTimeImmutable
    {
        return $this->takenAt;
    }
}
