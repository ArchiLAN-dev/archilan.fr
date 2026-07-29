<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\PublicProfileSlugsQueryInterface;
use App\Community\Domain\ValueObject\Audience;
use Doctrine\DBAL\Connection;

final readonly class DbalPublicProfileSlugsQuery implements PublicProfileSlugsQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function all(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select('u.slug', 'cp.updated_at')
            ->from('community_profile', 'cp')
            ->join('cp', $this->userTable, 'u', $qb->expr()->eq('u.id', 'cp.user_id'))
            ->where($qb->expr()->eq('cp.audience', ':audience'))
            ->andWhere($qb->expr()->isNotNull('u.slug'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->andWhere($qb->expr()->isNull('u.banned_at'))
            ->orderBy('u.slug', 'ASC')
            ->setParameter('audience', Audience::PUBLIC)
            ->executeQuery()
            ->fetchAllAssociative();

        $slugs = [];
        foreach ($rows as $row) {
            if (!is_string($row['slug']) || '' === $row['slug']) {
                continue;
            }
            $slugs[] = [
                'slug' => $row['slug'],
                'updatedAt' => is_string($row['updated_at']) ? $row['updated_at'] : '',
            ];
        }

        return $slugs;
    }
}
