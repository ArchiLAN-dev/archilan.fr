<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class RecordWeeklyGoal
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private HubInterface $mercureHub,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{entryId: string}|null null when sessionId not found (caller returns 200)
     */
    public function execute(
        string $externalSessionId,
        int $checksTotal,
        int $itemsTotal,
        \DateTimeImmutable $goalReachedAt,
    ): ?array {
        $entry = $this->entityManager->getRepository(WeeklyEntry::class)->findOneBy([
            'externalSessionId' => $externalSessionId,
        ]);

        if (!$entry instanceof WeeklyEntry) {
            $this->logger->warning('weekly_goal_callback.entry_not_found', [
                'externalSessionId' => $externalSessionId,
            ]);

            return null;
        }

        if (null !== $entry->getGoalReachedAt()) {
            return ['entryId' => $entry->getId()];
        }

        $run = $this->entityManager->find(WeeklyRun::class, $entry->getWeeklyRunId());
        if (!$run instanceof WeeklyRun) {
            $this->logger->warning('weekly_goal_callback.run_not_found', [
                'weeklyRunId' => $entry->getWeeklyRunId(),
            ]);

            return null;
        }

        $completionTimeSeconds = max(0, $goalReachedAt->getTimestamp() - $run->getStartedAt()->getTimestamp());
        $entry->recordGoal($goalReachedAt, $completionTimeSeconds, $checksTotal, $itemsTotal);
        $this->entityManager->flush();

        $userTable = $this->connection->quoteSingleIdentifier('user');
        $userRow = $this->connection->createQueryBuilder()
            ->select('u.display_name')
            ->from($userTable, 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $entry->getUserId())
            ->executeQuery()
            ->fetchAssociative();
        $displayName = (is_array($userRow) && is_string($userRow['display_name'])) ? $userRow['display_name'] : null;

        $payload = [
            'event' => 'goal_reached',
            'entryId' => $entry->getId(),
            'userId' => $entry->getUserId(),
            'displayName' => $displayName,
            'completionTimeSeconds' => $completionTimeSeconds,
            'checksTotal' => $checksTotal,
            'itemsTotal' => $itemsTotal,
            'goalReachedAt' => $goalReachedAt->format(\DateTimeInterface::ATOM),
        ];

        try {
            $this->mercureHub->publish(new Update(
                topics: [sprintf('weekly-runs/%s/leaderboard', $entry->getWeeklyRunId())],
                data: json_encode($payload, JSON_THROW_ON_ERROR),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for weekly leaderboard', [
                'weeklyRunId' => $entry->getWeeklyRunId(),
                'error' => $e->getMessage(),
            ]);
        }

        return ['entryId' => $entry->getId()];
    }
}
