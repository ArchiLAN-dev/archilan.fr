<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\CommunityUserDirectoryQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalCommunityUserDirectoryQuery implements CommunityUserDirectoryQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function userIdForSlug(string $slug): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $id = $qb
            ->select('u.id')
            ->from($this->userTable, 'u')
            ->where($qb->expr()->eq('u.slug', ':slug'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('slug', $slug)
            ->executeQuery()
            ->fetchOne();

        return is_string($id) ? $id : null;
    }

    public function cards(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            // The community display-name override wins over the account name (the pseudo shown everywhere).
            ->select('u.id', 'u.slug', 'COALESCE(cp.display_name, u.display_name) AS display_name', 'cp.avatar_url')
            ->from($this->userTable, 'u')
            ->leftJoin('u', 'community_profile', 'cp', $qb->expr()->eq('cp.user_id', 'u.id'))
            ->where($qb->expr()->in('u.id', ':ids'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $cards = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $slug = $row['slug'] ?? null;
            if (!is_string($id) || !is_string($slug)) {
                continue;
            }
            $cards[$id] = [
                'userId' => $id,
                'slug' => $slug,
                'displayName' => is_string($row['display_name'] ?? null) ? $row['display_name'] : null,
                'avatarUrl' => is_string($row['avatar_url'] ?? null) ? $row['avatar_url'] : null,
            ];
        }

        return $cards;
    }
}
