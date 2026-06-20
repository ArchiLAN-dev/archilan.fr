<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\AvatarUrlResolver;
use App\Community\Application\CommunityUserDirectoryQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class DbalCommunityUserDirectoryQuery implements CommunityUserDirectoryQueryInterface
{
    private string $userTable;

    public function __construct(
        private Connection $connection,
        private AvatarUrlResolver $avatarUrls,
    ) {
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
            ->select('u.id', 'u.slug', 'u.display_name', 'cp.avatar_url', 'cp.custom_avatar_key')
            ->from($this->userTable, 'u')
            ->leftJoin('u', 'community_profile', 'cp', $qb->expr()->eq('cp.user_id', 'u.id'))
            ->where($qb->expr()->in('u.id', ':ids'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            // Hide banned / currently-suspended members from every public card surface (story 30.29):
            // directory, friend lists, feed, notifications.
            ->andWhere('u.banned_at IS NULL AND (u.suspended_until IS NULL OR u.suspended_until <= :now)')
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->setParameter('now', new \DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
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
                // Custom uploaded avatar (presigned) wins over the cached external URL (story 30.27).
                'avatarUrl' => $this->avatarUrls->resolve(
                    is_string($row['custom_avatar_key'] ?? null) ? $row['custom_avatar_key'] : null,
                    is_string($row['avatar_url'] ?? null) ? $row['avatar_url'] : null,
                ),
            ];
        }

        return $cards;
    }
}
