<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\RunAuditLogRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionNotFoundException;
use App\Sessions\Domain\SessionRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class NotifyAllGoalCommand
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private RunAuditLogRepositoryInterface $auditLogs,
        private MessageBusInterface $messageBus,
        private RunnerGatewayInterface $runnerGateway,
    ) {
    }

    public function execute(string $sessionId): void
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            throw new SessionNotFoundException($sessionId);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return;
        }

        $now = new \DateTimeImmutable();
        $bridgePort = $session->getBridgePort() ?? 0;

        $session->transition(Session::STATUS_FINISHED, $now);
        $this->sessions->flush();

        $this->runnerGateway->stopSession($sessionId);
        $this->messageBus->dispatch(new ArchiveRunJob($sessionId, $bridgePort));

        $log = new RunAuditLog(
            bin2hex(random_bytes(16)),
            $sessionId,
            'bridge-system',
            'all_goal',
            null,
            $now,
        );
        $this->auditLogs->persist($log);
        $this->auditLogs->flush();
    }
}
