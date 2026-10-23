<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Repository\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\Repository\WeeklyRunRepositoryInterface;

final readonly class WeeklyRunSlotQuery
{
    public function __construct(
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyRunRepositoryInterface $runs,
        private SessionConfigResolver $configResolver,
    ) {
    }

    /**
     * Whether the player of an attempt sees their spheres (story 27.12): the "weekly" profile and the override of
     * the weekly's template, as the launch reads them.
     */
    public function spheresVisible(string $weeklyRunId): bool
    {
        $run = $this->runs->findById($weeklyRunId);

        return $this->configResolver->resolve(SessionType::Weekly, $run instanceof WeeklyRun ? $run->getTemplateId() : null)->server->spheresVisible;
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
