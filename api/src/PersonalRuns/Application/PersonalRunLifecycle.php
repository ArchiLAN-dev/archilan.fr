<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Application\ForceEndSessionCommand;
use App\Sessions\Domain\SessionNotFoundException;
use App\Sessions\Domain\SessionNotRunningException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class PersonalRunLifecycle
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private MessageBusInterface $messageBus,
        private ForceEndSessionCommand $forceEndSession,
    ) {
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function start(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if (in_array($run->getStatus(), Run::ACTIVE_STATUSES, true)) {
            return $this->result(found: true, blocked: true, blockReason: 'run_already_active');
        }

        $startableStatuses = [Run::STATUS_DRAFT, Run::STATUS_IDLE];
        if (!in_array($run->getStatus(), $startableStatuses, true)) {
            return $this->result(found: true, blocked: true, blockReason: 'run_not_startable');
        }

        $participants = $this->participants->findByRunId($run->getId());
        $anyHasSlots = false;
        foreach ($participants as $participant) {
            if ($participant->hasSlots()) {
                $anyHasSlots = true;
                break;
            }
        }
        if (!$anyHasSlots) {
            return $this->result(found: true, blocked: true, blockReason: 'games_required');
        }

        $run->start(new \DateTimeImmutable());
        $this->runs->flush();

        $this->messageBus->dispatch(new LaunchPersonalRunJob($run->getId()));

        return $this->result(found: true, runId: $run->getId(), status: $run->getStatus());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function stop(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if (Run::STATUS_ACTIVE !== $run->getStatus()) {
            return $this->result(found: true, blocked: true, blockReason: 'run_not_active');
        }

        $run->stop(new \DateTimeImmutable());
        $this->runs->flush();

        $this->messageBus->dispatch(new StopPersonalRunJob($run->getId()));

        return $this->result(found: true, runId: $run->getId(), status: $run->getStatus());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function markRunning(string $runId, string $host, int $port): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (Run::STATUS_STARTING !== $run->getStatus()) {
            return $this->result(found: true, blocked: true, blockReason: 'invalid_run_status');
        }

        $run->markRunning($host, $port, new \DateTimeImmutable());
        $this->runs->flush();

        return $this->result(found: true, runId: $run->getId(), status: $run->getStatus());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function markStopped(string $runId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (Run::STATUS_STOPPING !== $run->getStatus()) {
            return $this->result(found: true, blocked: true, blockReason: 'invalid_run_status');
        }

        $run->markStopped(new \DateTimeImmutable());
        $this->runs->flush();

        return $this->result(found: true, runId: $run->getId(), status: $run->getStatus());
    }

    /**
     * Owner-driven finish (story 17.15): complete an active run, then finalize its session (transition to
     * finished, stop the runner, dispatch the archive job that snapshots the bridge's real goal/check
     * state). Reuses the force-end mechanism rather than duplicating it.
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function finish(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if (Run::STATUS_ACTIVE !== $run->getStatus()) {
            return $this->result(found: true, blocked: true, blockReason: 'run_not_active');
        }

        $sessionId = $run->getSessionId();
        if (null === $sessionId) {
            return $this->result(found: true, blocked: true, blockReason: 'run_not_active');
        }

        // Finalize the session first so a session that is no longer running blocks the finish (and we
        // don't leave a completed run pointing at a still-running session).
        try {
            $this->forceEndSession->execute($sessionId, $callerId);
        } catch (SessionNotFoundException|SessionNotRunningException) {
            return $this->result(found: true, blocked: true, blockReason: 'run_not_active');
        }

        $run->complete(new \DateTimeImmutable());
        $this->runs->flush();

        return $this->result(found: true, runId: $run->getId(), status: $run->getStatus());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    private function result(
        bool $found = false,
        bool $authorized = true,
        bool $blocked = false,
        ?string $blockReason = null,
        ?string $runId = null,
        ?string $status = null,
    ): array {
        return [
            'found' => $found,
            'authorized' => $authorized,
            'blocked' => $blocked,
            'blockReason' => $blockReason,
            'runId' => $runId,
            'status' => $status,
        ];
    }
}
