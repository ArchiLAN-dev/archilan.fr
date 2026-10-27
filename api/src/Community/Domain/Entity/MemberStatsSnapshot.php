<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The facts of a member as the achievement recompute last built them (story 30.55).
 *
 * The recompute already builds every fact of every member each hour, then throws them away once the rules are
 * evaluated; keeping them makes the public stats page a single read. Written as a whole on each recompute, by an
 * upsert in the repository (read-only here).
 */
#[ORM\Entity]
#[ORM\Table(name: 'member_stats_snapshot')]
final class MemberStatsSnapshot
{
    /**
     * @param array<string, int> $facts
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
        private string $userId,

        #[ORM\Column(type: Types::JSON)]
        private array $facts,

        #[ORM\Column(name: 'computed_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $computedAt,
    ) {
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * @return array<string, int>
     */
    public function getFacts(): array
    {
        return $this->facts;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }
}
