<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;

final readonly class WeeklyRunSlotQuery
{
    public function __construct(private WeeklyEntryRepositoryInterface $entries)
    {
    }

    /**
     * Returns bridge connection details for an entry the given user is authorised to access.
     *
     * @return array{status: 'ok', bridgePort: int, externalSessionId: string}
     *                                                                         |array{status: 'not_found'|'forbidden'|'not_launched'}
     */
    public function findLaunchedEntryInfo(
        string $weeklyRunId,
        string $entryId,
        string $requestingUserId,
        bool $isAdmin,
    ): array {
        $entry = $this->entries->findById($entryId);

        if (!$entry instanceof WeeklyEntry || $entry->getWeeklyRunId() !== $weeklyRunId) {
            return ['status' => 'not_found'];
        }

        if (!$isAdmin && $entry->getUserId() !== $requestingUserId) {
            return ['status' => 'forbidden'];
        }

        $bridgePort = $entry->getBridgePort();
        $externalSessionId = $entry->getExternalSessionId();

        if (null === $bridgePort || null === $externalSessionId) {
            return ['status' => 'not_launched'];
        }

        return [
            'status' => 'ok',
            'bridgePort' => $bridgePort,
            'externalSessionId' => $externalSessionId,
        ];
    }
}
