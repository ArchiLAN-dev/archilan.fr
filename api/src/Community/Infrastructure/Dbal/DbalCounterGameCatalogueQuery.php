<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CounterGameCatalogueQueryInterface;
use App\Community\Domain\Enum\AchievementCounterKind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Story 30.57: reads `game` and `game_introspection` by name (no GameSelection import). The introspected names of
 * one game at a time only: those of every game together already ran PHP out of memory (story 38.20).
 */
final readonly class DbalCounterGameCatalogueQuery implements CounterGameCatalogueQueryInterface
{
    private const string ITEM_TYPE = 'item-received';

    public function __construct(private Connection $connection)
    {
    }

    public function games(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('g.id', 'g.name', 'g.archipelago_game_name')
            ->addSelect('CASE WHEN gi.location_names IS NOT NULL OR gi.item_names IS NOT NULL THEN 1 ELSE 0 END AS introspected')
            ->from('game', 'g')
            ->leftJoin('g', 'game_introspection', 'gi', 'gi.game_id = g.id')
            ->where("g.archipelago_game_name IS NOT NULL AND g.archipelago_game_name <> ''")
            ->orderBy('g.name')
            ->executeQuery()
            ->fetchAllAssociative();

        $games = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            $apName = $row['archipelago_game_name'] ?? null;
            if (is_string($id) && is_string($name) && is_string($apName)) {
                $games[] = ['id' => $id, 'name' => $name, 'archipelagoName' => $apName, 'introspected' => 1 === filter_var($row['introspected'] ?? null, \FILTER_VALIDATE_INT)];
            }
        }

        return $games;
    }

    public function archipelagoNames(array $gameIds): array
    {
        if ([] === $gameIds) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('g.id', 'g.archipelago_game_name')
            ->from('game', 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $gameIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $names = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $apName = $row['archipelago_game_name'] ?? null;
            if (is_string($id) && is_string($apName) && '' !== $apName) {
                $names[$id] = $apName;
            }
        }

        return $names;
    }

    public function names(string $gameId, AchievementCounterKind $kind): ?array
    {
        $location = AchievementCounterKind::Location === $kind;
        $row = $this->connection->createQueryBuilder()
            ->select('g.archipelago_game_name', $location ? 'gi.location_names AS names' : 'gi.item_names AS names', $location ? 'NULL AS name_groups' : 'gi.item_groups AS name_groups')
            ->from('game', 'g')
            ->leftJoin('g', 'game_introspection', 'gi', 'gi.game_id = g.id')
            ->where('g.id = :id')
            ->setParameter('id', $gameId)
            ->executeQuery()
            ->fetchAssociative();
        if (false === $row) {
            return null;
        }
        $apName = $row['archipelago_game_name'] ?? null;
        if (!is_string($apName) || '' === $apName) {
            return null;
        }

        $names = self::stringList($row['names'] ?? null);
        if ([] !== $names) {
            return ['source' => 'introspection', 'names' => $names, 'groups' => self::groups($row['name_groups'] ?? null)];
        }

        // Not introspected: what this game's sessions put in the feed, and only this game's.
        $column = $location ? 'location_name' : 'item_name';
        $gameColumn = $location ? 'sender_game' : 'receiver_game';
        $feed = $this->connection->createQueryBuilder()
            ->select('DISTINCT f.'.$column.' AS name')
            ->from('session_feed_event', 'f')
            ->where('f.type = :type')
            ->andWhere('f.'.$gameColumn.' = :game')
            ->andWhere('f.'.$column.' IS NOT NULL')
            ->setParameter('type', self::ITEM_TYPE)
            ->setParameter('game', $apName)
            ->executeQuery()
            ->fetchFirstColumn();

        return ['source' => 'feed', 'names' => array_values(array_filter($feed, is_string(...))), 'groups' => []];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $json): array
    {
        $decoded = self::decode($json);

        return null === $decoded ? [] : array_values(array_filter($decoded, static fn (mixed $name): bool => is_string($name) && '' !== $name));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function groups(mixed $json): array
    {
        $decoded = self::decode($json);
        if (null === $decoded) {
            return [];
        }

        $groups = [];
        foreach ($decoded as $group => $names) {
            if (!is_string($group) || !is_array($names)) {
                continue;
            }
            $members = array_values(array_filter($names, static fn (mixed $name): bool => is_string($name) && '' !== $name));
            if ([] !== $members) {
                $groups[$group] = $members;
            }
        }

        return $groups;
    }

    /**
     * @return array<mixed>|null
     */
    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || '' === $json) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
