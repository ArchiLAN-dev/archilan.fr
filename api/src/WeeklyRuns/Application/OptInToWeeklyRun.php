<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Clock\ClockInterface;

final readonly class OptInToWeeklyRun
{
    public function __construct(
        private WeeklyRunRepositoryInterface $runs,
        private WeeklyTemplateRepositoryInterface $templates,
        private WeeklyEntryRepositoryInterface $entries,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{id: string, weeklyRunId: string, userId: string, attemptNumber: int}
     */
    public function execute(string $weeklyRunId, string $userId): array
    {
        $run = $this->runs->findById($weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            throw new \DomainException('run_not_found');
        }

        if (WeeklyRun::STATUS_ACTIVE !== $run->getStatus()) {
            throw new \DomainException('run_not_active');
        }

        $template = $this->templates->findById($run->getTemplateId());
        if (!$template instanceof WeeklyTemplate) {
            throw new \DomainException('run_not_found');
        }

        $existingCount = $this->entries->countByRunAndUser($weeklyRunId, $userId);

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

        try {
            $this->entries->save($entry);
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
