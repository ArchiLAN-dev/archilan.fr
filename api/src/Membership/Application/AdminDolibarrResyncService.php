<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class AdminDolibarrResyncService
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
    ) {
    }

    public function dispatchAll(): int
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('m.id')
            ->from('memberships', 'm')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            if (is_string($row['id'])) {
                $this->bus->dispatch(new SyncMemberToDolibarrMessage($row['id']));
            }
        }

        return count($rows);
    }
}
