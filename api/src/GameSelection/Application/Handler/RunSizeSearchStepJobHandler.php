<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Message\RunSizeSearchStepJob;
use App\GameSelection\Application\Port\YamlTestQuotaInterface;
use App\GameSelection\Application\Support\YamlOptionValue;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\SizeSearch;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\GameSelection\Domain\Repository\SizeSearchRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Service\SlotDurationEstimate;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Story 56.7: moves a size search one step, never sleeping: it reads the pending generation (and comes back later
 * while it runs), records its duration, then starts the next one. A player out of YAML tests (story 9.57) makes the
 * search wait for the allowance rather than fail; past a long wait, it stops and says so.
 */
#[AsMessageHandler]
final readonly class RunSizeSearchStepJobHandler
{
    private const int POLL_DELAY_MS = 5000;
    /** About 7 minutes: past the orchestrator's own 5-minute test timeout. */
    private const int MAX_POLLS = 84;
    private const int QUOTA_DELAY_MS = 60_000;
    /** About 20 minutes: two full test allowances. */
    private const int MAX_QUOTA_WAITS = 20;

    public function __construct(
        private SizeSearchRepositoryInterface $searches,
        private GameRepositoryInterface $games,
        private RunnerGatewayInterface $runner,
        private YamlTestQuotaInterface $quota,
        private SlotDurationEstimate $durations,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunSizeSearchStepJob $job): void
    {
        $search = $this->searches->findById($job->searchId);
        if (!$search instanceof SizeSearch || !$search->isRunning() || $search->getRound() !== $job->round) {
            return;
        }
        $game = $this->games->findById($search->getGameId());
        $gameName = $game?->getArchipelagoGameName();
        if (!$game instanceof Game || null === $gameName) {
            $this->stop($search, 'Le jeu n\'est plus disponible.');

            return;
        }

        $pending = $search->getPending();
        if (null !== $pending) {
            $state = $this->runner->getSlotPreflight($pending['jobId']);
            if (null === $state || 'pending' === $state['status']) {
                if ($job->polls >= self::MAX_POLLS) {
                    $this->stop($search, null === $state
                        ? 'Le résultat d\'une génération est introuvable (orchestrateur redémarré ?) : relance la recherche.'
                        : 'Une génération a dépassé le délai maximal.');

                    return;
                }
                $this->later(new RunSizeSearchStepJob($search->getId(), $job->round, $job->polls + 1), self::POLL_DELAY_MS);

                return;
            }
            $locations = 'passed' === $state['status'] ? ($state['measures'][0]['locations'] ?? null) : null;
            $hours = null === $locations ? null : $this->durations->forSlot($search->getGameId(), [$search->getUserId()], $locations)->hours;
            $search->recordResult($locations, $hours, $this->clock->now());
        }

        $next = $search->nextTry($this->clock->now());
        if (null === $next) {
            $this->searches->save($search);

            return;
        }
        $yaml = YamlOptionValue::with($search->getYaml(), $gameName, $next['option'], $next['value']);
        if (null === $yaml) {
            $this->stop($search, 'Le YAML ne peut pas être modifié automatiquement.');

            return;
        }
        if (!$this->quota->consume($search->getUserId())) {
            $this->searches->save($search);
            if ($job->quotaWaits >= self::MAX_QUOTA_WAITS) {
                $this->stop($search, 'La limite de tests est restée atteinte trop longtemps : relance la recherche plus tard.');

                return;
            }
            $this->later(new RunSizeSearchStepJob($search->getId(), $job->round, 0, $job->quotaWaits + 1), self::QUOTA_DELAY_MS);

            return;
        }
        $jobId = $this->runner->startSlotPreflight($yaml, $game->getApworldHash());
        if (null === $jobId) {
            $this->stop($search, 'Le runner est indisponible : relance la recherche plus tard.');

            return;
        }
        $search->awaitResult($next['option'], $next['value'], $jobId, $this->clock->now());
        $this->searches->save($search);
        $this->later(new RunSizeSearchStepJob($search->getId(), $job->round), self::POLL_DELAY_MS);
    }

    private function later(RunSizeSearchStepJob $job, int $delayMs): void
    {
        $this->bus->dispatch($job, [new DelayStamp($delayMs)]);
    }

    private function stop(SizeSearch $search, string $reason): void
    {
        $this->logger->info('game.size_search.stopped', ['searchId' => $search->getId(), 'reason' => $reason]);
        $search->fail($reason, $this->clock->now());
        $this->searches->save($search);
    }
}
