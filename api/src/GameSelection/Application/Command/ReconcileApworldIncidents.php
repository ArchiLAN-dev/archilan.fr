<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Port\ExclusivePassLockInterface;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Domain\Entity\ApworldHealth;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldHealthRepositoryInterface;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use App\GameSelection\Domain\Service\ArchipelagoImageFreshness;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Clock\ClockInterface;

/**
 * Derives the apworld incidents from the orchestrator's test verdicts (story 38.1).
 *
 * A scheduled pull rather than a webhook: orchestrator webhooks are fire-and-forget without retry,
 * and a lost event would be an incident never opened - the exact failure this story removes. The
 * pass is idempotent, so a missed run is caught up by the next one.
 *
 * Only a completed verdict concludes anything. `pending`, `skipped`, an unchecked apworld or a
 * missing verdict leave the incidents as they are, and an unreachable runner changes nothing at
 * all: a runner outage must never "heal" a broken apworld.
 *
 * A verdict is computed once, then read every pass: only a new verdict (another `checkedAt`) is a
 * new failure, so reading the same one again neither counts a recurrence nor reopens an incident an
 * admin closed on it. One pass at a time: a second one, from the console for instance, skips.
 */
final readonly class ReconcileApworldIncidents
{
    public const string PASS = 'apworld_incidents_reconcile';

    private const string VERDICT_PASSED = 'passed';
    private const string VERDICT_FAILED = 'failed';

    public function __construct(
        private ServedApworldsQueryInterface $servedApworlds,
        private RunnerGatewayInterface $runnerGateway,
        private ApworldIncidentRepositoryInterface $incidents,
        private RecordApworldIncident $recordIncident,
        private ClockInterface $clock,
        private ExclusivePassLockInterface $lock,
        private ApworldHealthRepositoryInterface $health,
    ) {
    }

    /**
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null}>|null $verdicts
     *                                                                                                                                                                          the verdicts when the caller already read them for this pass; null reads them here
     */
    public function reconcile(?array $verdicts = null): ReconcileApworldIncidentsResult
    {
        if (!$this->lock->tryAcquire(self::PASS)) {
            return new ReconcileApworldIncidentsResult(true, alreadyRunning: true);
        }

        try {
            return $this->reconcileWith($verdicts ?? $this->runnerGateway->fetchApworldPreflights());
        } finally {
            $this->lock->release(self::PASS);
        }
    }

    /**
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null}> $verdicts
     */
    private function reconcileWith(array $verdicts): ReconcileApworldIncidentsResult
    {
        if ([] === $verdicts) {
            return new ReconcileApworldIncidentsResult(false);
        }

        $now = $this->clock->now();
        $opened = [];
        $resolved = [];
        $ignored = [];
        $retried = [];

        // Every active incident at once, instead of one query per served game.
        $activeByKey = [];
        foreach ($this->incidents->findAllActive() as $incident) {
            $activeByKey[self::key($incident->getGameId(), $incident->getApworldHash(), $incident->getType())] = $incident;
        }

        $servedHashByGame = [];
        foreach ($this->servedApworlds->servedApworlds() as $served) {
            $servedHashByGame[$served->gameId] = $served->apworldHash;

            $verdict = $verdicts[$served->apworldHash] ?? null;
            if (null === $verdict) {
                continue;
            }

            // The verdict-test incidents of this apworld: a failed test, or a regression of our image.
            $actives = array_values(array_filter([
                $activeByKey[self::key($served->gameId, $served->apworldHash, ApworldIncidentType::PreflightFailed)] ?? null,
                $activeByKey[self::key($served->gameId, $served->apworldHash, ApworldIncidentType::ImageRegression)] ?? null,
            ]));

            if (!\in_array($verdict['status'], [self::VERDICT_PASSED, self::VERDICT_FAILED], true)) {
                continue;
            }

            // Story 38.9: the apworld's memory, fed with each completed verdict once.
            $observation = '' !== $verdict['checkedAt'] ? $verdict['checkedAt'] : null;
            $health = $this->healthOf($served->gameId, $served->apworldHash);
            $isNewVerdict = null === $observation || $health->recordVerdict($verdict['status'], $observation, $verdict['image'] ?? null, $verdict['imageId'] ?? null);

            if (self::VERDICT_PASSED === $verdict['status']) {
                foreach ($actives as $active) {
                    $active->resolve($now, null);
                    $resolved[] = $active->getId();
                }
                continue;
            }

            // An admin forced the verdict (story 9.38 AC4): they have seen it and decided.
            if ($verdict['overridden']) {
                foreach ($actives as $active) {
                    $active->ignore($now, null);
                    $ignored[] = $active->getId();
                }
                continue;
            }

            if (!$isNewVerdict) {
                continue;
            }

            // A hash that passed and fails once is retested at once: a seed can be unlucky. The
            // second failure in a row alerts - as an image regression when our image changed since.
            if ($health->hasPassedBefore() && 1 === $health->getConsecutiveFailures()) {
                $retried[] = $served->apworldHash;
                continue;
            }
            [$type, $error] = self::failure($health, $verdict);

            $recording = $this->recordIncident->record($served->gameId, $served->apworldHash, $type, $error, $observation);
            if (ApworldIncidentRecordOutcome::Opened === $recording->outcome && null !== $recording->incidentId) {
                $opened[] = $recording->incidentId;
            }
        }

        // An incident about a hash the game no longer serves is over: a new apworld replaced it,
        // or the game lost its apworld. The new hash gets its own verdict and, if needed, its own
        // incident.
        foreach ($activeByKey as $incident) {
            if (!$incident->isActive() || !$incident->getType()->followsServedApworld()) {
                continue;
            }
            if (($servedHashByGame[$incident->getGameId()] ?? null) === $incident->getApworldHash()) {
                continue;
            }
            $incident->resolve($now, null);
            $resolved[] = $incident->getId();
        }

        $this->incidents->flush();

        // After the commit, like every side effect: the retest's verdict reaches a later pass.
        foreach (array_unique($retried) as $hash) {
            $this->runnerGateway->runApworldPreflight($hash);
        }

        return new ReconcileApworldIncidentsResult(true, $opened, $resolved, $ignored, retriedApworldHashes: array_values(array_unique($retried)));
    }

    private function healthOf(string $gameId, string $apworldHash): ApworldHealth
    {
        $health = $this->health->find($gameId, $apworldHash);
        if (null === $health) {
            $health = ApworldHealth::start(bin2hex(random_bytes(16)), $gameId, $apworldHash);
            $this->health->save($health);
        }

        return $health;
    }

    /**
     * @param array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null} $verdict
     *
     * @return array{ApworldIncidentType, string}
     */
    private static function failure(ApworldHealth $health, array $verdict): array
    {
        $image = $verdict['image'] ?? null;
        $imageChanged = $health->hasPassedBefore()
            && null !== $image
            && null !== $health->getLastSuccessImage()
            && false === ArchipelagoImageFreshness::isCurrent($health->getLastSuccessImage(), $health->getLastSuccessImageId(), $image, $verdict['imageId'] ?? null);
        if (!$imageChanged) {
            return [ApworldIncidentType::PreflightFailed, $verdict['error']];
        }

        return [ApworldIncidentType::ImageRegression, sprintf(
            'Passait sur %s, échoue deux fois de suite sur %s. %s',
            $health->getLastSuccessImage(),
            $image,
            $verdict['error'],
        )];
    }

    private static function key(string $gameId, string $apworldHash, ApworldIncidentType $type): string
    {
        return $gameId."\0".$apworldHash."\0".$type->value;
    }
}
