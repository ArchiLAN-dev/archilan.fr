<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
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
 */
final readonly class ReconcileApworldIncidents
{
    private const string VERDICT_PASSED = 'passed';
    private const string VERDICT_FAILED = 'failed';

    public function __construct(
        private ServedApworldsQueryInterface $servedApworlds,
        private RunnerGatewayInterface $runnerGateway,
        private ApworldIncidentRepositoryInterface $incidents,
        private RecordApworldIncident $recordIncident,
        private ClockInterface $clock,
    ) {
    }

    public function reconcile(): ReconcileApworldIncidentsResult
    {
        $verdicts = $this->runnerGateway->fetchApworldPreflights();
        if ([] === $verdicts) {
            return new ReconcileApworldIncidentsResult(false);
        }

        $now = $this->clock->now();
        $opened = [];
        $resolved = [];
        $ignored = [];

        $servedHashByGame = [];
        foreach ($this->servedApworlds->servedApworlds() as $served) {
            $servedHashByGame[$served->gameId] = $served->apworldHash;

            $verdict = $verdicts[$served->apworldHash] ?? null;
            if (null === $verdict) {
                continue;
            }

            $active = $this->incidents->findActive($served->gameId, $served->apworldHash, ApworldIncidentType::PreflightFailed);

            if (self::VERDICT_PASSED === $verdict['status']) {
                if (null !== $active) {
                    $active->resolve($now, null);
                    $resolved[] = $active->getId();
                }
                continue;
            }

            if (self::VERDICT_FAILED !== $verdict['status']) {
                continue;
            }

            // An admin forced the verdict (story 9.38 AC4): they have seen it and decided.
            if ($verdict['overridden']) {
                if (null !== $active) {
                    $active->ignore($now, null);
                    $ignored[] = $active->getId();
                }
                continue;
            }

            $recording = $this->recordIncident->record(
                $served->gameId,
                $served->apworldHash,
                ApworldIncidentType::PreflightFailed,
                $verdict['error'],
            );
            if (ApworldIncidentRecordOutcome::Opened === $recording->outcome && null !== $recording->incidentId) {
                $opened[] = $recording->incidentId;
            }
        }

        // An incident about a hash the game no longer serves is over: a new apworld replaced it,
        // or the game lost its apworld. The new hash gets its own verdict and, if needed, its own
        // incident.
        foreach ($this->incidents->findAllActive() as $incident) {
            if (!$incident->getType()->followsServedApworld()) {
                continue;
            }
            if (($servedHashByGame[$incident->getGameId()] ?? null) === $incident->getApworldHash()) {
                continue;
            }
            $incident->resolve($now, null);
            $resolved[] = $incident->getId();
        }

        $this->incidents->flush();

        return new ReconcileApworldIncidentsResult(true, $opened, $resolved, $ignored);
    }
}
