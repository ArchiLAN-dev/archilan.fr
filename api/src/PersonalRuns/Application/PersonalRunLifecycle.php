<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\PersonalRun;
use App\PersonalRuns\Domain\PersonalRunParticipant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class PersonalRunLifecycle
{
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
        $run = $this->entityManager->find(PersonalRun::class, $runId);

        if (!$run instanceof PersonalRun) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if (in_array($run->getStatus(), PersonalRun::ACTIVE_STATUSES, true)) {
            return $this->result(found: true, blocked: true, blockReason: 'run_already_active');
        }

        $startableStatuses = [PersonalRun::STATUS_DRAFT, PersonalRun::STATUS_IDLE];
        if (!in_array($run->getStatus(), $startableStatuses, true)) {
            return $this->result(found: true, blocked: true, blockReason: 'run_not_startable');
        }

        $participants = $this->entityManager->getRepository(PersonalRunParticipant::class)
            ->findBy(['personalRunId' => $run->getId()]);
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
        $run = $this->entityManager->find(PersonalRun::class, $runId);

        if (!$run instanceof PersonalRun) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if (PersonalRun::STATUS_ACTIVE !== $run->getStatus()) {
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
        $run = $this->entityManager->find(PersonalRun::class, $runId);

        if (!$run instanceof PersonalRun) {
            return $this->result(found: false);
        }

        if (PersonalRun::STATUS_STARTING !== $run->getStatus()) {
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
        $run = $this->entityManager->find(PersonalRun::class, $runId);

        if (!$run instanceof PersonalRun) {
            return $this->result(found: false);
        }

        if (PersonalRun::STATUS_STOPPING !== $run->getStatus()) {
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
