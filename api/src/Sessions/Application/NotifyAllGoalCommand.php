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

final readonly class NotifyAllGoalCommand
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return array{found: bool, skipped: bool}
     */
    public function execute(string $sessionId): array
    {
        try {
            $session = $this->findOrFail(Session::class, $sessionId);
        } catch (\RuntimeException) {
            return ['found' => false, 'skipped' => false];
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return ['found' => true, 'skipped' => true];
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
            'bridge-system',
            'all_goal',
            null,
            $now,
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return ['found' => true, 'skipped' => false];
    }
}
