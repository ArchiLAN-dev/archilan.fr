<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Identity\Application\MemberDisplayNameQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Community-side adapter for Identity's {@see MemberDisplayNameQueryInterface}: reads the optional
 * display-name override from community_profile (story: current-user pseudo = community display name).
 */
final readonly class DbalMemberDisplayNameQuery implements MemberDisplayNameQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function displayNameFor(string $userId): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $value = $qb
            ->select('cp.display_name')
            ->from('community_profile', 'cp')
            ->where($qb->expr()->eq('cp.user_id', ':userId'))
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) && '' !== $value ? $value : null;
    }
}
