<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\Session;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ForceEndSessionCommand
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return array{found: bool, notRunning: bool, payload: array<string, mixed>}
     */
    public function execute(string $sessionId, string $actorId): array
    {
        try {
            $session = $this->findOrFail(Session::class, $sessionId);
        } catch (\RuntimeException) {
            return ['found' => false, 'notRunning' => false, 'payload' => []];
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return ['found' => true, 'notRunning' => true, 'payload' => []];
        }

        $now = new \DateTimeImmutable();
        $port = $session->getPort() ?? 0;
        $bridgePort = $session->getBridgePort() ?? 0;

        $session->transition(Session::STATUS_FINISHED, $now);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new StopRunJob($sessionId, $port, $bridgePort));
        $this->messageBus->dispatch(new ArchiveRunJob($sessionId, $bridgePort));

        $log = new RunAuditLog(
            bin2hex(random_bytes(16)),
            $sessionId,
            $actorId,
            'force_end',
            null,
            $now,
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return ['found' => true, 'notRunning' => false, 'payload' => $session->payload()];
    }
}
