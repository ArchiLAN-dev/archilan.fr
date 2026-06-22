<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\EventCatalogueQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Reads the `event` table by name (no Events-domain import) to feed the admin event-scope picker and the
 * real-event validation for event-goal achievement rules (story 30.32).
 */
final readonly class DbalEventCatalogueQuery implements EventCatalogueQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function selectableEvents(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('e.id AS id', 'e.title AS title')
            ->from('event', 'e')
            ->where($qb->expr()->neq('e.status', ':draft'))
            ->setParameter('draft', 'draft')
            ->orderBy('e.starts_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $title = $row['title'] ?? null;
            if (is_string($id) && is_string($title)) {
                $events[] = ['id' => $id, 'title' => $title];
            }
        }

        return $events;
    }

    public function exists(string $eventId): bool
    {
        $qb = $this->connection->createQueryBuilder();

        return false !== $qb
            ->select('1')
            ->from('event', 'e')
            ->where($qb->expr()->eq('e.id', ':id'))
            ->setParameter('id', $eventId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }
}
