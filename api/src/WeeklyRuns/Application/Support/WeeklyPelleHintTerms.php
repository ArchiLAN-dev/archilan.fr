<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Support;

use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Repository\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\Repository\WeeklyRunRepositoryInterface;

/**
 * What a hint costs in pelles in a weekly attempt (story 41.8): the "weekly" profile and the override of the
 * weekly's template, as the launch reads them. Only the player of the attempt (or an admin) gets an answer.
 */
final readonly class WeeklyPelleHintTerms
{
    public function __construct(
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyRunRepositoryInterface $runs,
        private SessionConfigResolver $configResolver,
    ) {
    }

    /**
     * @return array{entry: WeeklyEntry, bridgeSessionId: string|null, enabled: bool, itemPrice: int, locationPrice: int}|'not_found'|'forbidden'
     */
    public function of(string $runId, string $entryId, string $userId, bool $isAdmin): array|string
    {
        $entry = $this->entries->findById($entryId);
        $run = $this->runs->findById($runId);
        if (!$entry instanceof WeeklyEntry || !$run instanceof WeeklyRun || $entry->getWeeklyRunId() !== $runId) {
            return 'not_found';
        }
        if (!$isAdmin && $entry->getUserId() !== $userId) {
            return 'forbidden';
        }

        $server = $this->configResolver->resolve(SessionType::Weekly, $run->getTemplateId())->server;

        return [
            'entry' => $entry,
            'bridgeSessionId' => $entry->getExternalSessionId(),
            'enabled' => $server->pelleHints,
            'itemPrice' => $server->pelleItemHintPrice,
            'locationPrice' => $server->pelleLocationHintPrice,
        ];
    }
}
