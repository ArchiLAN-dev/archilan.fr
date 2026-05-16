<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlayerHistoryQuery
{
    private string $sessionTable;
    private string $slotTable;
    private string $registrationTable;
    private string $runTable;
    private string $eventTable;
    private string $gameTable;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->sessionTable = $entityManager->getClassMetadata(Session::class)->getTableName();
        $this->slotTable = $entityManager->getClassMetadata(SessionSlot::class)->getTableName();
        $this->registrationTable = $entityManager->getClassMetadata(Registration::class)->getTableName();
        $this->runTable = $entityManager->getClassMetadata(Run::class)->getTableName();
        $this->eventTable = $entityManager->getClassMetadata(Event::class)->getTableName();
        $this->gameTable = $entityManager->getClassMetadata(Game::class)->getTableName();
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
        $eventQb = $this->connection->createQueryBuilder();
        $eventRows = $eventQb
            ->select(
                's.id AS session_id',
                'e.title AS event_name',
                's.finished_at',
                'g.name AS game',
                'slot.checks_done',
                'slot.items_received',
                'slot.goal_reached_at',
                'slot.was_released',
            )
            ->from($this->slotTable, 'slot')
            ->join('slot', $this->registrationTable, 'reg', $eventQb->expr()->eq('reg.id', 'slot.registration_id'))
            ->join('slot', $this->sessionTable, 's', $eventQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('s', $this->eventTable, 'e', $eventQb->expr()->eq('e.id', 's.event_id'))
            ->join('slot', $this->gameTable, 'g', $eventQb->expr()->eq('g.id', 'slot.game_id'))
            ->where($eventQb->expr()->eq('reg.user_id', ':userId'))
            ->andWhere($eventQb->expr()->eq('s.status', ':status'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAllAssociative();

        $prQb = $this->connection->createQueryBuilder();
        $prRows = $prQb
            ->select(
                's.id AS session_id',
                'pr.title AS event_name',
                's.finished_at',
                'g.name AS game',
                'slot.checks_done',
                'slot.items_received',
                'slot.goal_reached_at',
                'slot.was_released',
            )
            ->from($this->slotTable, 'slot')
            ->join('slot', $this->sessionTable, 's', $prQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('s', $this->runTable, 'pr', $prQb->expr()->eq('pr.session_id', 's.id'))
            ->join('slot', $this->gameTable, 'g', $prQb->expr()->eq('g.id', 'slot.game_id'))
            ->where($prQb->expr()->eq('slot.registration_id', ':userId'))
            ->andWhere($prQb->expr()->eq('s.status', ':status'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_merge($eventRows, $prRows);
    }
}
