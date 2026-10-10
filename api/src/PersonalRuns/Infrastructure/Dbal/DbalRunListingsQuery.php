<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Dbal;

use App\PersonalRuns\Application\Query\RunListingsQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalRunListingsQuery implements RunListingsQueryInterface
{
    private const int LIMIT = 100;

    public function __construct(private Connection $connection)
    {
    }

    public function listingsFor(string $viewerId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('r.id', 'r.title', 'r.owner_id', 'r.pitch', 'r.planned_for', 'r.listed_at', 'r.seats_wanted', 'r.game_selection_config')
            ->addSelect("(SELECT string_agg(p.user_id, ',') FROM run_participant p WHERE p.personal_run_id = r.id AND p.user_id <> r.owner_id) AS participant_ids")
            ->from('run', 'r')
            ->where('r.openness = :members')
            ->andWhere('r.status = :draft')
            ->andWhere('r.owner_id <> :viewer')
            ->andWhere('r.pitch IS NOT NULL AND r.listed_at IS NOT NULL')
            ->andWhere('NOT EXISTS (SELECT 1 FROM run_participant v WHERE v.personal_run_id = r.id AND v.user_id = :viewer)')
            ->andWhere('NOT EXISTS (SELECT 1 FROM community_block b WHERE (b.blocker_id = r.owner_id AND b.blocked_id = :viewer) OR (b.blocker_id = :viewer AND b.blocked_id = r.owner_id))')
            ->orderBy('r.listed_at', 'DESC')
            // Story 43.19: a page, not the whole table.
            ->setMaxResults(self::LIMIT)
            ->setParameter('members', Run::OPEN_MEMBERS)
            ->setParameter('draft', Run::STATUS_DRAFT)
            ->setParameter('viewer', $viewerId)
            ->executeQuery()
            ->fetchAllAssociative();

        $listings = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $title = $row['title'] ?? null;
            $ownerId = $row['owner_id'] ?? null;
            $pitch = $row['pitch'] ?? null;
            $listedAt = $row['listed_at'] ?? null;
            if (!is_string($id) || !is_string($title) || !is_string($ownerId) || !is_string($pitch) || !is_string($listedAt)) {
                continue;
            }
            $plannedFor = $row['planned_for'] ?? null;
            $seats = $row['seats_wanted'] ?? null;
            $participants = $row['participant_ids'] ?? null;
            $listings[] = [
                'runId' => $id,
                'title' => $title,
                'ownerId' => $ownerId,
                'pitch' => $pitch,
                'plannedFor' => is_string($plannedFor) ? new \DateTimeImmutable($plannedFor) : null,
                'listedAt' => new \DateTimeImmutable($listedAt),
                'seatsWanted' => is_numeric($seats) ? (int) $seats : null,
                'participantIds' => is_string($participants) && '' !== $participants ? explode(',', $participants) : [],
                'gameIds' => self::gameIds($row['game_selection_config'] ?? null),
            ];
        }

        return $listings;
    }

    public function gameNames(array $gameIds): array
    {
        if ([] === $gameIds) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('g.id', 'g.name')
            ->from('game', 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $gameIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $names = [];
        foreach ($rows as $row) {
            if (is_string($row['id'] ?? null) && is_string($row['name'] ?? null)) {
                $names[$row['id']] = $row['name'];
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function gameIds(mixed $config): array
    {
        $decoded = is_string($config) ? json_decode($config, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $entry) {
            $id = is_array($entry) ? ($entry['gameId'] ?? null) : null;
            if (is_string($id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
