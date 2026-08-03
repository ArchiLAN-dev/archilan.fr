<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Sessions\Application\Query\ViewableRecapsQuery;

final readonly class PlayerHistoryQuery
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PlayerHistoryQueryInterface $historyQuery,
        private ViewableRecapsQuery $viewableRecaps,
    ) {
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{page: int, limit: int, total: int}
     * }|null
     */
    public function execute(string $slug, int $page, int $limit, ?string $viewerId = null): ?array
    {
        $user = $this->userRepository->findBySlug($slug);
        if (!$user instanceof User) {
            return null;
        }

        $offset = ($page - 1) * $limit;
        $allRows = $this->historyQuery->fetchForUser($user->getId());

        usort($allRows, static function (array $a, array $b): int {
            $aAt = is_string($a['finished_at'] ?? null) ? $a['finished_at'] : '';
            $bAt = is_string($b['finished_at'] ?? null) ? $b['finished_at'] : '';

            return strcmp($bAt, $aAt);
        });

        $total = count($allRows);
        $pageRows = array_slice($allRows, $offset, $limit);

        // Story 32.20: each row links to its recap only when this viewer may open it - resolved for
        // the page in one pass, so a 20-entry page costs two grouped reads rather than sixty.
        $sessionIds = [];
        foreach ($pageRows as $row) {
            $sessionId = $row['session_id'] ?? null;
            if (is_string($sessionId) && '' !== $sessionId) {
                $sessionIds[] = $sessionId;
            }
        }
        $recapViewable = $this->viewableRecaps->forViewer(array_values(array_unique($sessionIds)), $viewerId);

        $data = array_map(function (array $row) use ($recapViewable): array {
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
                'isWeekly' => (bool) ($row['is_weekly'] ?? false),
                'recapAccessible' => is_string($row['session_id'] ?? null)
                    && ($recapViewable[$row['session_id']] ?? false),
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
}
