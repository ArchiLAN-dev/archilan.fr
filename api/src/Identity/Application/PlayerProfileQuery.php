<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlayerProfileQuery
{
    private string $sessionTable;
    private string $slotTable;
    private string $registrationTable;
    private string $runTable;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->sessionTable = $entityManager->getClassMetadata(Session::class)->getTableName();
        $this->slotTable = $entityManager->getClassMetadata(SessionSlot::class)->getTableName();
        $this->registrationTable = $entityManager->getClassMetadata(Registration::class)->getTableName();
        $this->runTable = $entityManager->getClassMetadata(Run::class)->getTableName();
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
        $eventQb = $this->connection->createQueryBuilder();
        $eventRow = $eventQb
            ->select(
                'COUNT(DISTINCT s.id) AS runs_participated',
                'COUNT(DISTINCT CASE WHEN slot.goal_reached_at IS NOT NULL THEN s.id END) AS goal_completions',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.items_received ELSE 0 END), 0) AS total_items_received',
            )
            ->from($this->slotTable, 'slot')
            ->join('slot', $this->registrationTable, 'reg', $eventQb->expr()->eq('reg.id', 'slot.registration_id'))
            ->join('slot', $this->sessionTable, 's', $eventQb->expr()->eq('s.id', 'slot.session_id'))
            ->where($eventQb->expr()->eq('reg.user_id', ':userId'))
            ->andWhere($eventQb->expr()->eq('s.status', ':status'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAssociative();

        $prQb = $this->connection->createQueryBuilder();
        $prRow = $prQb
            ->select(
                'COUNT(DISTINCT s.id) AS runs_participated',
                'COUNT(DISTINCT CASE WHEN slot.goal_reached_at IS NOT NULL THEN s.id END) AS goal_completions',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.items_received ELSE 0 END), 0) AS total_items_received',
            )
            ->from($this->slotTable, 'slot')
            ->join('slot', $this->sessionTable, 's', $prQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('s', $this->runTable, 'pr', $prQb->expr()->eq('pr.session_id', 's.id'))
            ->where($prQb->expr()->eq('slot.registration_id', ':userId'))
            ->andWhere($prQb->expr()->eq('s.status', ':status'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAssociative();

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
