<?php

declare(strict_types=1);

namespace App\Registrations\Infrastructure;

use App\Registrations\Application\AccountRegistrationsQueryInterface;
use App\Registrations\Domain\Registration;
use Doctrine\DBAL\Connection;

final readonly class DbalAccountRegistrationsQuery implements AccountRegistrationsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findForUser(string $userId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'r.id AS registration_id',
                'r.status AS registration_status',
                'r.submitted_at',
                'r.game_slots',
                'e.id AS event_id',
                'e.title AS event_title',
                'e.starts_at AS event_starts_at',
            )
            ->from('registration', 'r')
            ->join('r', 'event', 'e', $qb->expr()->eq('e.id', 'r.event_id'))
            ->where($qb->expr()->eq('r.user_id', ':userId'))
            ->setParameter('userId', $userId)
            ->orderBy('e.starts_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            return [];
        }

        $sessionStatusByEvent = $this->fetchLatestSessionStatuses($rows);

        $result = [];
        foreach ($rows as $row) {
            $regStatus = is_string($row['registration_status']) ? $row['registration_status'] : '';
            $submittedAt = $row['submitted_at'];
            $eventId = is_string($row['event_id']) ? $row['event_id'] : '';

            $frontendStatus = match (true) {
                Registration::STATUS_CANCELLED === $regStatus => 'cancelled',
                is_string($submittedAt) && '' !== $submittedAt => 'confirmed',
                default => 'pending',
            };

            $gameSlotsRaw = $row['game_slots'];
            $slotCount = 0;
            if (is_string($gameSlotsRaw)) {
                $decoded = json_decode($gameSlotsRaw, true);
                $slotCount = is_array($decoded) ? count($decoded) : 0;
            }

            $result[] = [
                'registrationId' => is_string($row['registration_id']) ? $row['registration_id'] : '',
                'eventSlug' => $eventId,
                'eventTitle' => is_string($row['event_title']) ? $row['event_title'] : '',
                'eventStartDate' => is_string($row['event_starts_at']) ? $row['event_starts_at'] : null,
                'registrationStatus' => $frontendStatus,
                'slotCount' => $slotCount,
                'sessionStatus' => $sessionStatusByEvent[$eventId] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Returns a map of event_id → latest session status.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private function fetchLatestSessionStatuses(array $rows): array
    {
        /** @var list<string> $eventIds */
        $eventIds = array_values(array_unique(array_filter(
            array_column($rows, 'event_id'),
            'is_string',
        )));

        if ([] === $eventIds) {
            return [];
        }

        $sqb = $this->connection->createQueryBuilder();
        $placeholders = array_map(
            static fn (string $id): string => $sqb->createNamedParameter($id),
            $eventIds,
        );

        $sessionRows = $sqb
            ->select('s.event_id', 's.status', 's.created_at')
            ->from('session', 's')
            ->where($sqb->expr()->in('s.event_id', $placeholders))
            ->orderBy('s.created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<string, string> $byEvent */
        $byEvent = [];
        foreach ($sessionRows as $sRow) {
            $eventId = is_string($sRow['event_id']) ? $sRow['event_id'] : '';
            $status = is_string($sRow['status']) ? $sRow['status'] : '';
            if ('' !== $eventId && !isset($byEvent[$eventId])) {
                $byEvent[$eventId] = $status;
            }
        }

        return $byEvent;
    }
}
