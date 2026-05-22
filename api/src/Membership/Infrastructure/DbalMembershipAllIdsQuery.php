<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Membership\Application\MembershipAllIdsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalMembershipAllIdsQuery implements MembershipAllIdsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<string>
     */
    public function execute(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('m.id')
            ->from('memberships', 'm')
            ->executeQuery()
            ->fetchAllAssociative();

        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row['id'])) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }
}
