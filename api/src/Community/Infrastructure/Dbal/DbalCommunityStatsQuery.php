<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CommunityStatsQueryInterface;
use App\Community\Domain\Entity\Friendship;
use App\Shared\Application\Support\StatsPeriod;
use App\Shared\Infrastructure\Dbal\DbalSlotCheckSource;
use App\Shared\Infrastructure\Dbal\DbalStatsReader;
use Doctrine\DBAL\Connection;

/**
 * Reads the Community section of the admin statistics page (story 42.1). Like the admin dashboard counters,
 * it reads the tables of the contexts it sums up (accounts, memberships, session feed) in plain SQL: a
 * statistics read is not a reason to give those contexts a dependency on Community.
 */
final readonly class DbalCommunityStatsQuery implements CommunityStatsQueryInterface
{
    public function __construct(
        private Connection $connection,
        private DbalStatsReader $reader,
    ) {
    }

    public function stats(StatsPeriod $period, \DateTimeImmutable $now): array
    {
        $user = $this->connection->quoteSingleIdentifier('user');

        return [
            'accounts' => $this->reader->count("SELECT COUNT(*) FROM {$user} WHERE deleted_at IS NULL"),
            'members' => $this->reader->count(
                "SELECT COUNT(DISTINCT user_id) FROM memberships WHERE status = 'active' AND expires_at >= :now",
                ['now' => $now->format(\DATE_ATOM)],
            ),
            'accountsCreated' => $this->reader->trend($period, "FROM {$user} WHERE 1 = 1", 'created_at'),
            'membershipsStarted' => $this->reader->trend($period, 'FROM memberships WHERE 1 = 1', 'started_at'),
            'activePlayers' => $this->reader->trend(
                $period,
                DbalSlotCheckSource::from(),
                'f.occurred_at',
                DbalSlotCheckSource::params(),
                DbalSlotCheckSource::types(),
                DbalSlotCheckSource::player(),
            ),
            'friendshipsAccepted' => $this->reader->trend($period, 'FROM community_friendship WHERE status = :accepted', 'responded_at', ['accepted' => Friendship::ACCEPTED]),
            'achievementsUnlocked' => $this->reader->trend($period, 'FROM community_achievement_grant WHERE 1 = 1', 'unlocked_at'),
        ];
    }
}
