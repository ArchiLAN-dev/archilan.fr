<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\CommunityAdminIdsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalCommunityAdminIdsQuery implements CommunityAdminIdsQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function adminUserIds(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('u.id', 'u.roles')
            ->from($this->userTable, 'u')
            ->where($qb->expr()->isNull('u.deleted_at'))
            ->executeQuery()
            ->fetchAllAssociative();

        // Decode the roles JSON in PHP (portable across Postgres/SQLite) rather than a DB-specific JSON op.
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }
            $decoded = is_string($row['roles'] ?? null) ? json_decode($row['roles'], true) : null;
            if (is_array($decoded) && in_array('ROLE_ADMIN', $decoded, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
