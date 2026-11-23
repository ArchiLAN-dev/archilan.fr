<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Dbal;

use App\Sessions\Application\Query\SessionFeedEntriesQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-import-type FeedEntry from SessionFeedEntriesQueryInterface
 */
final readonly class DbalSessionFeedEntriesQuery implements SessionFeedEntriesQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return \Generator<int, FeedEntry>
     */
    public function forSession(string $sessionId): \Generator
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'id', 'type', 'text', 'occurred_at', 'item_id', 'item_name', 'item_flags', 'location_id', 'location_name',
                'sender_slot', 'sender_name', 'sender_game', 'receiver_slot', 'receiver_name', 'receiver_game', 'victim_names',
            )
            ->from('session_feed_event')
            ->where('session_id = :session')
            ->setParameter('session', $sessionId)
            ->orderBy('occurred_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->executeQuery()
            ->iterateAssociative();

        foreach ($rows as $row) {
            yield [
                'id' => self::string($row['id'] ?? null),
                'type' => self::string($row['type'] ?? null),
                'text' => self::string($row['text'] ?? null),
                'occurredAt' => new \DateTimeImmutable(self::string($row['occurred_at'] ?? null))->format(\DateTimeInterface::ATOM),
                'item' => [
                    'id' => self::int($row['item_id'] ?? null),
                    'name' => self::nullableString($row['item_name'] ?? null),
                    'flags' => self::int($row['item_flags'] ?? null),
                ],
                'location' => ['id' => self::int($row['location_id'] ?? null), 'name' => self::nullableString($row['location_name'] ?? null)],
                'sender' => [
                    'slot' => self::int($row['sender_slot'] ?? null),
                    'name' => self::nullableString($row['sender_name'] ?? null),
                    'game' => self::nullableString($row['sender_game'] ?? null),
                ],
                'receiver' => [
                    'slot' => self::int($row['receiver_slot'] ?? null),
                    'name' => self::nullableString($row['receiver_name'] ?? null),
                    'game' => self::nullableString($row['receiver_game'] ?? null),
                ],
                'victims' => self::victims($row['victim_names'] ?? null),
            ];
        }
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<string>
     */
    private static function victims(mixed $json): array
    {
        if (!is_string($json) || '' === $json) {
            return [];
        }
        $names = json_decode($json, true);
        if (!is_array($names)) {
            return [];
        }

        return array_values(array_filter($names, is_string(...)));
    }
}
