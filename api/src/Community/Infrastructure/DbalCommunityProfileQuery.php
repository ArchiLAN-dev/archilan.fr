<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\CommunityProfileQueryInterface;
use App\Identity\Application\PlayerStatsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalCommunityProfileQuery implements CommunityProfileQueryInterface
{
    private string $userTable;

    public function __construct(
        private Connection $connection,
        private PlayerStatsQueryInterface $playerStats,
    ) {
        // "user" is a reserved word in Postgres - quote it, like DbalUserDirectoryQuery does.
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function forSlug(string $slug): ?array
    {
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select('u.id', 'u.slug', 'u.display_name', 'u.created_at', 'u.roles')
            ->from($this->userTable, 'u')
            ->where($qb->expr()->eq('u.slug', ':slug'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('slug', $slug)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        $userId = $row['id'];
        $resolvedSlug = $row['slug'];
        if (!is_string($userId) || !is_string($resolvedSlug)) {
            return null;
        }

        $displayName = is_string($row['display_name'] ?? null) ? $row['display_name'] : null;
        $joinedAt = is_string($row['created_at'] ?? null)
            ? (new \DateTimeImmutable($row['created_at']))->format(\DateTimeInterface::ATOM)
            : (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $stats = $this->playerStats->computeForUser($userId);
        $runsParticipated = $stats['runs_participated'];
        $goalCompletions = $stats['goal_completions'];

        return [
            'userId' => $userId,
            'slug' => $resolvedSlug,
            'displayName' => $displayName,
            'joinedAt' => $joinedAt,
            'isAdmin' => $this->isAdmin($row['roles'] ?? null),
            'stats' => [
                'runsParticipated' => $runsParticipated,
                'goalCompletions' => $goalCompletions,
                'goalCompletionRate' => $runsParticipated > 0
                    ? round($goalCompletions / $runsParticipated, 6)
                    : 0.0,
                'totalChecksDone' => $stats['total_checks_done'],
                'totalItemsReceived' => $stats['total_items_received'],
            ],
        ];
    }

    /**
     * ROLE_ADMIN is a stable role (unlike the stale-prone ROLE_MEMBER), so reading it from the user row is
     * fine for a display-only badge. The column stores a JSON array of role strings.
     */
    private function isAdmin(mixed $rawRoles): bool
    {
        if (!is_string($rawRoles)) {
            return false;
        }
        $decoded = json_decode($rawRoles, true);

        return is_array($decoded) && in_array('ROLE_ADMIN', $decoded, true);
    }
}
