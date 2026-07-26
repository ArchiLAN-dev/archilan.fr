<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\RecapSuperlativesQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Reads the session_recap projection cross-context by table name (the DbalEventParticipationQuery
 * precedent) so the achievement engine learns about recap superlatives without importing the
 * Sessions domain (story 32.4).
 *
 * Scope mirrors the recap privacy model: finished sessions of public events, reached through a
 * submitted registration - which naturally excludes personal/weekly runs (their slots do not point
 * at a registration row). The superlatives JSON is decoded in PHP; a malformed column contributes
 * nothing.
 */
final readonly class DbalRecapSuperlativesQuery implements RecapSuperlativesQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function superlativeCountsFor(string $userId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('sr.superlatives AS superlatives', 'slot.slot_id AS slot_id')
            ->from('session_recap', 'sr')
            ->join('sr', 'session', 's', 's.id = sr.session_id')
            ->join('s', 'event', 'e', 'e.id = s.event_id')
            ->join('s', 'session_slot', 'slot', 'slot.session_id = s.id')
            ->join('slot', 'registration', 'r', 'r.id = slot.registration_id AND r.event_id = s.event_id')
            ->where($qb->expr()->eq('r.user_id', ':userId'))
            ->andWhere($qb->expr()->eq('r.status', ':reserved'))
            ->andWhere('r.submitted_at IS NOT NULL')
            ->andWhere($qb->expr()->eq('s.status', ':finished'))
            ->andWhere($qb->expr()->eq('e.is_public', ':public'))
            ->setParameter('userId', $userId)
            ->setParameter('reserved', 'reserved')
            ->setParameter('finished', 'finished')
            ->setParameter('public', true, \Doctrine\DBAL\ParameterType::BOOLEAN)
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $slotId = $row['slot_id'] ?? null;
            $json = $row['superlatives'] ?? null;
            if (!is_string($slotId) || !is_string($json)) {
                continue;
            }
            $superlatives = json_decode($json, true);
            if (!is_array($superlatives)) {
                continue;
            }
            foreach ($superlatives as $superlative) {
                if (!is_array($superlative)) {
                    continue;
                }
                $key = $superlative['key'] ?? null;
                $winnerSlotId = $superlative['slotId'] ?? null;
                if (!is_string($key) || $winnerSlotId !== $slotId) {
                    continue;
                }
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
