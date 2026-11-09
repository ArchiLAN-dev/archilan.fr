<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Dbal;

use App\Sessions\Application\Query\PasseurOpeningQueryInterface;
use App\Sessions\Application\Query\PasseurOpeningRow;
use App\Sessions\Domain\Entity\PasseurOpening;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalPasseurOpeningQuery implements PasseurOpeningQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forSession(string $sessionId): array
    {
        return $this->rows($this->select()->where('session_id = :session')->setParameter('session', $sessionId));
    }

    public function forEvent(string $eventId): array
    {
        return $this->rows($this->select()->where('event_id = :event')->setParameter('event', $eventId));
    }

    public function unseenBy(string $sessionId, string $absentUserId): array
    {
        return $this->rows($this->select()
            ->where('session_id = :session')
            ->andWhere('absent_user_id = :absent')
            ->andWhere('status = :opened')
            ->andWhere('absent_seen_at IS NULL')
            ->setParameter('session', $sessionId)
            ->setParameter('absent', $absentUserId)
            ->setParameter('opened', PasseurOpening::STATUS_OPENED));
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'session_id', 'status', 'buyer_name', 'absent_name', 'finding_slot', 'location_id', 'location_name', 'item_name', 'kind', 'price', 'share', 'failure_code', 'created_at', 'settled_at')
            ->from('passeur_opening')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC');
    }

    /**
     * @return list<PasseurOpeningRow>
     */
    private function rows(QueryBuilder $qb): array
    {
        $rows = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $created = $row['created_at'] ?? null;
            if (!is_string($created)) {
                continue;
            }
            $settled = $row['settled_at'] ?? null;
            $failure = $row['failure_code'] ?? null;
            $rows[] = new PasseurOpeningRow(
                self::text($row['id'] ?? null),
                self::text($row['session_id'] ?? null),
                self::text($row['status'] ?? null),
                self::text($row['buyer_name'] ?? null),
                self::text($row['absent_name'] ?? null),
                self::int($row['finding_slot'] ?? null),
                self::int($row['location_id'] ?? null),
                self::text($row['location_name'] ?? null),
                self::text($row['item_name'] ?? null),
                self::text($row['kind'] ?? null),
                self::int($row['price'] ?? null),
                self::int($row['share'] ?? null),
                is_string($failure) ? $failure : null,
                new \DateTimeImmutable($created),
                is_string($settled) ? new \DateTimeImmutable($settled) : null,
            );
        }

        return $rows;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
