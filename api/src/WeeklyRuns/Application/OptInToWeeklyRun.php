<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class OptInToWeeklyRun
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{id: string, weeklyRunId: string, userId: string, attemptNumber: int}
     */
    public function execute(string $weeklyRunId, string $userId): array
    {
        $run = $this->entityManager->find(WeeklyRun::class, $weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            throw new \DomainException('run_not_found');
        }

        if (WeeklyRun::STATUS_ACTIVE !== $run->getStatus()) {
            throw new \DomainException('run_not_active');
        }

        $template = $this->entityManager->find(WeeklyTemplate::class, $run->getTemplateId());
        if (!$template instanceof WeeklyTemplate) {
            throw new \DomainException('run_not_found');
        }

        $countRaw = $this->connection->createQueryBuilder()
            ->select('COUNT(we.id)')
            ->from('weekly_entries', 'we')
            ->where('we.weekly_run_id = :runId')
            ->andWhere('we.user_id = :userId')
            ->setParameter('runId', $weeklyRunId)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        $existingCount = (false !== $countRaw && is_numeric($countRaw)) ? (int) $countRaw : 0;

        $maxAttempts = $template->getMaxAttempts();
        if (null !== $maxAttempts && $existingCount >= $maxAttempts) {
            throw new \DomainException('max_attempts_reached');
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $entry = new WeeklyEntry(
            id: bin2hex(random_bytes(8)),
            weeklyRunId: $weeklyRunId,
            userId: $userId,
            attemptNumber: $existingCount + 1,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->entityManager->persist($entry);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('entry_conflict');
        }

        return [
            'id' => $entry->getId(),
            'weeklyRunId' => $entry->getWeeklyRunId(),
            'userId' => $entry->getUserId(),
            'attemptNumber' => $entry->getAttemptNumber(),
        ];
    }
}
