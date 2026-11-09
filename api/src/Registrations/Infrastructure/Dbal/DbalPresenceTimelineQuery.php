<?php

declare(strict_types=1);

namespace App\Registrations\Infrastructure\Dbal;

use App\Registrations\Application\Query\PresenceTimelineQueryInterface;
use App\Registrations\Domain\Entity\Registration;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Story 52.2. */
final readonly class DbalPresenceTimelineQuery implements PresenceTimelineQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function timeline(string $eventId): ?array
    {
        $qb = $this->connection->createQueryBuilder();
        $event = $qb->select('e.starts_at', 'e.ends_at')
            ->from('event', 'e')
            ->where($qb->expr()->eq('e.id', ':event'))
            ->setParameter('event', $eventId)
            ->executeQuery()
            ->fetchAssociative();
        if (false === $event || !is_string($event['starts_at']) || !is_string($event['ends_at'])) {
            return null;
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select('r.id', 'r.user_id', 'u.display_name')
            ->from('registration', 'r')
            ->join('r', $this->connection->quoteSingleIdentifier('user'), 'u', $qb->expr()->eq('u.id', 'r.user_id'))
            ->where($qb->expr()->eq('r.event_id', ':event'))
            ->andWhere($qb->expr()->eq('r.status', ':reserved'))
            ->andWhere($qb->expr()->isNotNull('r.submitted_at'))
            ->orderBy('LOWER(u.display_name)')
            ->addOrderBy('r.id')
            ->setParameter('event', $eventId)
            ->setParameter('reserved', Registration::STATUS_RESERVED)
            ->executeQuery()
            ->fetchAllAssociative();

        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row['id'])) {
                $ids[] = $row['id'];
            }
        }
        $windows = $this->windowsOf($ids);

        $registrants = [];
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['user_id'])) {
                continue;
            }
            $registrants[] = [
                'registrationId' => $row['id'],
                'userId' => $row['user_id'],
                'displayName' => is_string($row['display_name']) ? $row['display_name'] : '',
                'windows' => $windows[$row['id']] ?? [],
            ];
        }

        return [
            'eventStartsAt' => new \DateTimeImmutable($event['starts_at'])->format(\DateTimeInterface::ATOM),
            'eventEndsAt' => new \DateTimeImmutable($event['ends_at'])->format(\DateTimeInterface::ATOM),
            'registrants' => $registrants,
        ];
    }

    public function isOutsideWindows(string $registrationId, \DateTimeImmutable $at): bool
    {
        $qb = $this->connection->createQueryBuilder();
        $row = $qb->select('COUNT(*) AS total', 'COUNT(*) FILTER (WHERE w.starts_at <= :at AND w.ends_at > :at) AS holding')
            ->from('registration_presence_window', 'w')
            ->where($qb->expr()->eq('w.registration_id', ':registration'))
            ->setParameter('registration', $registrationId)
            ->setParameter('at', $at->format('Y-m-d H:i:sP'))
            ->executeQuery()
            ->fetchAssociative();
        if (false === $row) {
            return false;
        }

        return (int) (is_numeric($row['total']) ? $row['total'] : 0) > 0
            && 0 === (int) (is_numeric($row['holding']) ? $row['holding'] : 0);
    }

    /**
     * @param list<string> $registrationIds
     *
     * @return array<string, list<array{startsAt: string, endsAt: string}>>
     */
    private function windowsOf(array $registrationIds): array
    {
        if ([] === $registrationIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select('w.registration_id', 'w.starts_at', 'w.ends_at')
            ->from('registration_presence_window', 'w')
            ->where($qb->expr()->in('w.registration_id', ':ids'))
            ->orderBy('w.starts_at')
            ->setParameter('ids', $registrationIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $windows = [];
        foreach ($rows as $row) {
            if (!is_string($row['registration_id']) || !is_string($row['starts_at']) || !is_string($row['ends_at'])) {
                continue;
            }
            $windows[$row['registration_id']][] = [
                'startsAt' => new \DateTimeImmutable($row['starts_at'])->format(\DateTimeInterface::ATOM),
                'endsAt' => new \DateTimeImmutable($row['ends_at'])->format(\DateTimeInterface::ATOM),
            ];
        }

        return $windows;
    }
}
