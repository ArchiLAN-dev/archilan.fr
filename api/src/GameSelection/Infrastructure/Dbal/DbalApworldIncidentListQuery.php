<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Dbal;

use App\GameSelection\Application\Query\ApworldIncidentAdmin;
use App\GameSelection\Application\Query\ApworldIncidentListItem;
use App\GameSelection\Application\Query\ApworldIncidentListQueryInterface;
use App\GameSelection\Application\Query\ApworldIncidentListScope;
use App\GameSelection\Application\Query\ApworldIncidentSummary;
use App\Sessions\Application\Support\GenerationFailureParser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalApworldIncidentListQuery implements ApworldIncidentListQueryInterface
{
    private const array ACTIVE_STATUSES = ['open', 'acknowledged'];
    private const array CLOSED_STATUSES = ['resolved', 'ignored'];

    public function __construct(private Connection $connection)
    {
    }

    public function list(ApworldIncidentListScope $scope, ?string $gameId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select(
            'i.id', 'i.game_id', 'i.apworld_hash', 'i.type', 'i.status', 'i.error', 'i.opened_at', 'i.last_seen_at',
            'i.occurrences', 'i.acknowledged_by', 'i.acknowledged_at', 'i.closed_at', 'i.closed_by',
            'g.name AS game_name', 'ack.display_name AS acknowledged_by_name', 'closer.display_name AS closed_by_name',
        )
            ->from('apworld_incident', 'i')
            ->leftJoin('i', 'game', 'g', 'g.id = i.game_id')
            ->leftJoin('i', '"user"', 'ack', 'ack.id = i.acknowledged_by')
            ->leftJoin('i', '"user"', 'closer', 'closer.id = i.closed_by')
            // Active incidents first, oldest first: what has waited longest comes on top. Then the
            // history, most recently closed first.
            ->orderBy('CASE WHEN i.closed_at IS NULL THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('CASE WHEN i.closed_at IS NULL THEN i.opened_at END', 'ASC')
            ->addOrderBy('i.closed_at', 'DESC')
            ->addOrderBy('i.id', 'ASC');

        $statuses = match ($scope) {
            ApworldIncidentListScope::Active => self::ACTIVE_STATUSES,
            ApworldIncidentListScope::Closed => self::CLOSED_STATUSES,
            ApworldIncidentListScope::All => null,
        };
        if (null !== $statuses) {
            $qb->andWhere($qb->expr()->in('i.status', ':statuses'))
                ->setParameter('statuses', $statuses, ArrayParameterType::STRING);
        }
        if (null !== $gameId) {
            $qb->andWhere($qb->expr()->eq('i.game_id', ':gameId'))
                ->setParameter('gameId', $gameId);
        }

        $items = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $item = $this->item($row);
            if (null !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function summary(): ApworldIncidentSummary
    {
        $qb = $this->connection->createQueryBuilder();
        $row = $qb->select(
            "COUNT(*) FILTER (WHERE i.status IN ('open', 'acknowledged')) AS active",
            "COUNT(*) FILTER (WHERE i.status = 'open') AS unacknowledged",
        )
            ->from('apworld_incident', 'i')
            ->executeQuery()
            ->fetchAssociative();

        return new ApworldIncidentSummary(
            is_array($row) && is_numeric($row['active'] ?? null) ? (int) $row['active'] : 0,
            is_array($row) && is_numeric($row['unacknowledged'] ?? null) ? (int) $row['unacknowledged'] : 0,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function item(array $row): ?ApworldIncidentListItem
    {
        $id = $row['id'] ?? null;
        $gameId = $row['game_id'] ?? null;
        $hash = $row['apworld_hash'] ?? null;
        $type = $row['type'] ?? null;
        $status = $row['status'] ?? null;
        $error = $row['error'] ?? null;
        $openedAt = self::date($row['opened_at'] ?? null);
        $lastSeenAt = self::date($row['last_seen_at'] ?? null);
        $occurrences = $row['occurrences'] ?? null;
        if (!is_string($id) || !is_string($gameId) || !is_string($hash) || !is_string($type) || !is_string($status)
            || !is_string($error) || null === $openedAt || null === $lastSeenAt || !is_numeric($occurrences)) {
            return null;
        }

        return new ApworldIncidentListItem(
            $id,
            $gameId,
            // A removed game keeps its incidents readable by id.
            is_string($row['game_name'] ?? null) ? $row['game_name'] : $gameId,
            $hash,
            $type,
            $status,
            GenerationFailureParser::summarize($error),
            $error,
            $openedAt,
            $lastSeenAt,
            (int) $occurrences,
            self::admin($row['acknowledged_by'] ?? null, $row['acknowledged_by_name'] ?? null),
            self::date($row['acknowledged_at'] ?? null),
            self::date($row['closed_at'] ?? null),
            self::admin($row['closed_by'] ?? null, $row['closed_by_name'] ?? null),
        );
    }

    private static function admin(mixed $id, mixed $name): ?ApworldIncidentAdmin
    {
        if (!is_string($id)) {
            return null;
        }

        return new ApworldIncidentAdmin($id, is_string($name) ? $name : 'Admin supprimé');
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
