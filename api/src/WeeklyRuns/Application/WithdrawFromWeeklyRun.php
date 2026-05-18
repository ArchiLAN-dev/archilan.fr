<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WithdrawFromWeeklyRun
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function execute(string $weeklyRunId, string $entryId, string $userId): void
    {
        $entry = $this->entityManager->find(WeeklyEntry::class, $entryId);
        if (!$entry instanceof WeeklyEntry) {
            throw new \DomainException('entry_not_found');
        }

        if ($entry->getWeeklyRunId() !== $weeklyRunId || $entry->getUserId() !== $userId) {
            throw new \DomainException('forbidden');
        }

        if (null !== $entry->getExternalSessionId()) {
            throw new \DomainException('session_already_started');
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }
}
