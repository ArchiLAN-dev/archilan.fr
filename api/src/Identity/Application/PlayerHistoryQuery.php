<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlayerHistoryQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{page: int, limit: int, total: int}
     * }|null
     */
    public function execute(string $slug, int $page, int $limit): ?array
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['slug' => $slug]);
        if (!$user instanceof User) {
            return null;
        }

        $offset = ($page - 1) * $limit;
        $allRows = $this->fetchHistory($user->getId());

        usort($allRows, static function (array $a, array $b): int {
            $aAt = is_string($a['finished_at'] ?? null) ? $a['finished_at'] : '';
            $bAt = is_string($b['finished_at'] ?? null) ? $b['finished_at'] : '';

            return strcmp($bAt, $aAt);
        });

        $total = count($allRows);
        $pageRows = array_slice($allRows, $offset, $limit);

        $data = array_map(function (array $row): array {
            $goalReachedAt = is_string($row['goal_reached_at'] ?? null) ? $row['goal_reached_at'] : null;
            $wasReleased = (bool) ($row['was_released'] ?? false);
            $isInvalidated = $wasReleased && null === $goalReachedAt;

            return [
                'sessionId' => is_string($row['session_id'] ?? null) ? $row['session_id'] : '',
                'eventName' => is_string($row['event_name'] ?? null) ? $row['event_name'] : '',
                'finishedAt' => is_string($row['finished_at'] ?? null) ? $row['finished_at'] : null,
                'game' => is_string($row['game'] ?? null) ? $row['game'] : '',
                'checksDone' => is_numeric($row['checks_done'] ?? null) ? (int) $row['checks_done'] : 0,
                'itemsReceived' => is_numeric($row['items_received'] ?? null) ? (int) $row['items_received'] : 0,
                'goalReachedAt' => $goalReachedAt,
                'wasReleased' => $wasReleased,
                'isInvalidated' => $isInvalidated,
            ];
        }, $pageRows);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchHistory(string $userId): array
    {
        $eventRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    s.id AS session_id,
                    e.title AS event_name,
                    s.finished_at,
                    g.name AS game,
                    slot.checks_done,
                    slot.items_received,
                    slot.goal_reached_at,
                    slot.was_released
                FROM archipelago_session_slots slot
                JOIN event_registrations reg ON slot.registration_id = reg.id
                JOIN archipelago_sessions s ON slot.session_id = s.id
                JOIN events e ON s.event_id = e.id
                JOIN games g ON slot.game_id = g.id
                WHERE reg.user_id = :userId AND s.status = 'finished'
            SQL,
            ['userId' => $userId],
        );

        $prRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    s.id AS session_id,
                    pr.title AS event_name,
                    s.finished_at,
                    g.name AS game,
                    slot.checks_done,
                    slot.items_received,
                    slot.goal_reached_at,
                    slot.was_released
                FROM archipelago_session_slots slot
                JOIN archipelago_sessions s ON slot.session_id = s.id
                JOIN personal_runs pr ON pr.session_id = s.id
                JOIN games g ON slot.game_id = g.id
                WHERE slot.registration_id = :userId AND s.status = 'finished'
            SQL,
            ['userId' => $userId],
        );

        return array_merge($eventRows, $prRows);
    }
}
