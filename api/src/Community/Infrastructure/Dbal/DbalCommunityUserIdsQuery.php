<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CommunityUserIdsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalCommunityUserIdsQuery implements CommunityUserIdsQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function allUserIds(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('u.id')
            ->from($this->userTable, 'u')
            ->where($qb->expr()->isNull('u.deleted_at'))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($rows, is_string(...)));
    }
}
