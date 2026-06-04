<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;

final readonly class WithdrawFromWeeklyRun
{
    public function __construct(private WeeklyEntryRepositoryInterface $entries)
    {
    }

    public function execute(string $weeklyRunId, string $entryId, string $userId): void
    {
        $entry = $this->entries->findById($entryId);
        if (null === $entry) {
            throw new \DomainException('entry_not_found');
        }

        if ($entry->getWeeklyRunId() !== $weeklyRunId || $entry->getUserId() !== $userId) {
            throw new \DomainException('forbidden');
        }

        if (null !== $entry->getExternalSessionId()) {
            throw new \DomainException('session_already_started');
        }

        $this->entries->remove($entry);
    }
}
