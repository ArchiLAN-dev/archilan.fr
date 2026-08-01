<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Command\ForceEndSessionCommand;
use App\Sessions\Domain\Exception\SessionNotFoundException;
use App\Sessions\Domain\Exception\SessionNotRunningException;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class PersonalRunLifecycle
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private MessageBusInterface $messageBus,
        private ForceEndSessionCommand $forceEndSession,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Publish or unpublish the finished run's recap (story 32.5). Owner-only.
     *
     * @throws NotFoundException  when the run does not exist
     * @throws ForbiddenException when the caller is not the owner
     */
    public function setRecapVisibility(string $runId, string $callerId, bool $public): RunRecapVisibilityResult
    {
        $run = $this->runs->findById($runId);
        if (null === $run) {
            throw new NotFoundException('Run introuvable.');
        }
        if (!$run->isOwnedBy($callerId)) {
            throw new ForbiddenException('Accès refusé.');
        }

        if ($public) {
            $run->publishRecap($this->clock->now());
        } else {
            $run->unpublishRecap($this->clock->now());
        }
        $this->runs->save($run);

        return new RunRecapVisibilityResult($run->getId(), $run->isRecapPublic());
    }

    /**
     * @throws NotFoundException   when the run does not exist
     * @throws ForbiddenException  when the caller does not own the run
     * @throws ValidationException when the run cannot be started in its current state
     */
    public function start(string $runId, string $callerId): RunLifecycleResult
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            throw new NotFoundException('Run introuvable.');
        }

        if (!$run->isOwnedBy($callerId)) {
            throw new ForbiddenException('Accès refusé.');
        }

        if (in_array($run->getStatus(), Run::ACTIVE_STATUSES, true)) {
            throw new ValidationException('Démarrage impossible dans l\'état actuel.', [], 'run_already_active');
        }

        $startableStatuses = [Run::STATUS_DRAFT, Run::STATUS_IDLE];
        if (!in_array($run->getStatus(), $startableStatuses, true)) {
            throw new ValidationException('Démarrage impossible dans l\'état actuel.', [], 'run_not_startable');
        }

        $participants = $this->participants->findByRunId($run->getId());
        $anyHasSlots = array_any($participants, fn ($participant) => $participant->hasSlots());
        if (!$anyHasSlots) {
            throw new ValidationException('Démarrage impossible dans l\'état actuel.', [], 'games_required');
        }

        $run->start($this->clock->now());
        $this->runs->flush();

        $this->messageBus->dispatch(new LaunchPersonalRunJob($run->getId()));

        return new RunLifecycleResult($run->getId(), $run->getStatus());
    }

    /**
     * @throws NotFoundException   when the run does not exist
     * @throws ForbiddenException  when the caller does not own the run
     * @throws ValidationException when the run is not active
     */
    public function stop(string $runId, string $callerId): RunLifecycleResult
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            throw new NotFoundException('Run introuvable.');
        }

        if (!$run->isOwnedBy($callerId)) {
            throw new ForbiddenException('Accès refusé.');
        }

        if (Run::STATUS_ACTIVE !== $run->getStatus()) {
            throw new ValidationException('Arrêt impossible dans l\'état actuel.', [], 'run_not_active');
        }

        $run->stop($this->clock->now());
        $this->runs->flush();

        $this->messageBus->dispatch(new StopPersonalRunJob($run->getId()));

        return new RunLifecycleResult($run->getId(), $run->getStatus());
    }

    /**
     * @throws NotFoundException   when the run does not exist
     * @throws ValidationException when the run is not in the starting state
     */
    public function markRunning(string $runId, string $host, int $port): RunLifecycleResult
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            throw new NotFoundException('Run introuvable.');
        }

        if (Run::STATUS_STARTING !== $run->getStatus()) {
            throw new ValidationException('Transition de run invalide.', [], 'invalid_run_status');
        }

        $run->markRunning($host, $port, $this->clock->now());
        $this->runs->flush();

        return new RunLifecycleResult($run->getId(), $run->getStatus());
    }

    /**
     * @throws NotFoundException   when the run does not exist
     * @throws ValidationException when the run is not in the stopping state
     */
    public function markStopped(string $runId): RunLifecycleResult
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            throw new NotFoundException('Run introuvable.');
        }

        if (Run::STATUS_STOPPING !== $run->getStatus()) {
            throw new ValidationException('Transition de run invalide.', [], 'invalid_run_status');
        }

        $run->markStopped($this->clock->now());
        $this->runs->flush();

        return new RunLifecycleResult($run->getId(), $run->getStatus());
    }

    /**
     * Owner-driven finish (story 17.15): complete an active run, then finalize its session (transition to
     * finished, stop the runner, dispatch the archive job that snapshots the bridge's real goal/check
     * state). Reuses the force-end mechanism rather than duplicating it.
     *
     * @throws NotFoundException  when the run does not exist
     * @throws ForbiddenException when the caller does not own the run
     * @throws ConflictException  when the run cannot be finished in its current state
     */
    public function finish(string $runId, string $callerId): RunLifecycleResult
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            throw new NotFoundException('Run introuvable.');
        }

        if (!$run->isOwnedBy($callerId)) {
            throw new ForbiddenException('Accès refusé.');
        }

        if (Run::STATUS_ACTIVE !== $run->getStatus()) {
            throw new ConflictException('Impossible de terminer la run dans son état actuel.', 'run_not_active');
        }

        $sessionId = $run->getSessionId();
        if (null === $sessionId) {
            throw new ConflictException('Impossible de terminer la run dans son état actuel.', 'run_not_active');
        }

        // Finalize the session first so a session that is no longer running blocks the finish (and we
        // don't leave a completed run pointing at a still-running session).
        try {
            $this->forceEndSession->execute($sessionId, $callerId);
        } catch (SessionNotFoundException|SessionNotRunningException) {
            throw new ConflictException('Impossible de terminer la run dans son état actuel.', 'run_not_active');
        }

        $run->complete($this->clock->now());
        $this->runs->flush();

        return new RunLifecycleResult($run->getId(), $run->getStatus());
    }
}
