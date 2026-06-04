<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;

/**
 * Used by the Sessions context to verify a session ID belongs to a weekly entry
 * when it cannot be found as a regular Session.
 */
final readonly class WeeklyEntrySessionCheck
{
    public function __construct(private WeeklyEntryRepositoryInterface $entries)
    {
    }

    public function existsByExternalSessionId(string $externalSessionId): bool
    {
        return null !== $this->entries->findByExternalSessionId($externalSessionId);
    }
}
