<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class PersonalRunLifecycle
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function start(string $runId, string $callerId): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
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

        $participants = $this->entityManager->getRepository(RunParticipant::class)
            ->findBy(['runId' => $run->getId()]);
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
        $this->entityManager->flush();

        $this->messageBus->dispatch(new LaunchPersonalRunJob($run->getId()));

        return $this->result(found: true, runId: $run->getId(), status: $run->getStatus());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function stop(string $runId, string $callerId): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if (Run::STATUS_ACTIVE !== $run->getStatus()) {
            return $this->result(found: true, blocked: true, blockReason: 'run_not_active');
        }

        $run->stop(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->messageBus->dispatch(new StopPersonalRunJob($run->getId()));

        return $this->result(found: true, runId: $run->getId(), status: $run->getStatus());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function markRunning(string $runId, string $host, int $port): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
            return $this->result(found: false);
        }

        if (Run::STATUS_STARTING !== $run->getStatus()) {
            return $this->result(found: true, blocked: true, blockReason: 'invalid_run_status');
        }

        $run->markRunning($host, $port, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->result(found: true, runId: $run->getId(), status: $run->getStatus());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, runId: string|null, status: string|null}
     */
    public function markStopped(string $runId): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
            return $this->result(found: false);
        }

        if (Run::STATUS_STOPPING !== $run->getStatus()) {
            return $this->result(found: true, blocked: true, blockReason: 'invalid_run_status');
        }

        $run->markStopped(new \DateTimeImmutable());
        $this->entityManager->flush();

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
