<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlayerProfileQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     slug: string|null,
     *     displayName: string|null,
     *     joinedAt: string,
     *     stats: array{
     *         runsParticipated: int,
     *         goalCompletions: int,
     *         goalCompletionRate: float,
     *         totalChecksDone: int,
     *         totalItemsReceived: int
     *     }
     * }|null
     */
    public function execute(string $slug): ?array
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['slug' => $slug]);
        if (!$user instanceof User) {
            return null;
        }

        $stats = $this->computeStats($user->getId());
        $runsParticipated = $stats['runs_participated'];
        $goalCompletions = $stats['goal_completions'];

        return [
            'slug' => $user->getSlug(),
            'displayName' => $user->getDisplayName(),
            'joinedAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'stats' => [
                'runsParticipated' => $runsParticipated,
                'goalCompletions' => $goalCompletions,
                'goalCompletionRate' => $runsParticipated > 0
                    ? round($goalCompletions / $runsParticipated, 6)
                    : 0.0,
                'totalChecksDone' => $stats['total_checks_done'],
                'totalItemsReceived' => $stats['total_items_received'],
            ],
        ];
    }

    /**
     * @return array{runs_participated: int, goal_completions: int, total_checks_done: int, total_items_received: int}
     */
    private function computeStats(string $userId): array
    {
        $eventRow = $this->connection->fetchAssociative(
            <<<SQL
                SELECT
                    COUNT(DISTINCT s.id) AS runs_participated,
                    COUNT(DISTINCT CASE WHEN slot.goal_reached_at IS NOT NULL THEN s.id END) AS goal_completions,
                    COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                                      THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done,
                    COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                                      THEN slot.items_received ELSE 0 END), 0) AS total_items_received
                FROM archipelago_session_slots slot
                JOIN event_registrations reg ON slot.registration_id = reg.id
                JOIN archipelago_sessions s ON slot.session_id = s.id
                WHERE reg.user_id = :userId AND s.status = 'finished'
            SQL,
            ['userId' => $userId],
        );

        $prRow = $this->connection->fetchAssociative(
            <<<SQL
                SELECT
                    COUNT(DISTINCT s.id) AS runs_participated,
                    COUNT(DISTINCT CASE WHEN slot.goal_reached_at IS NOT NULL THEN s.id END) AS goal_completions,
                    COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                                      THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done,
                    COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                                      THEN slot.items_received ELSE 0 END), 0) AS total_items_received
                FROM archipelago_session_slots slot
                JOIN archipelago_sessions s ON slot.session_id = s.id
                WHERE slot.registration_id = :userId AND s.status = 'finished'
                  AND EXISTS (SELECT 1 FROM personal_runs pr WHERE pr.session_id = s.id)
            SQL,
            ['userId' => $userId],
        );

        return [
            'runs_participated' => $this->intVal($eventRow, 'runs_participated') + $this->intVal($prRow, 'runs_participated'),
            'goal_completions' => $this->intVal($eventRow, 'goal_completions') + $this->intVal($prRow, 'goal_completions'),
            'total_checks_done' => $this->intVal($eventRow, 'total_checks_done') + $this->intVal($prRow, 'total_checks_done'),
            'total_items_received' => $this->intVal($eventRow, 'total_items_received') + $this->intVal($prRow, 'total_items_received'),
        ];
    }

    /** @param array<string, mixed>|false $row */
    private function intVal(array|false $row, string $key): int
    {
        if (false === $row) {
            return 0;
        }

        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
    }
}
