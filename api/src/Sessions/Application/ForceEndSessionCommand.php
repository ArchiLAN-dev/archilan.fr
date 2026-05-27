<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\RunAuditLogRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionNotFoundException;
use App\Sessions\Domain\SessionNotRunningException;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Infrastructure\RunnerGatewayInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ForceEndSessionCommand
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private RunAuditLogRepositoryInterface $auditLogs,
        private MessageBusInterface $messageBus,
        private RunnerGatewayInterface $runnerGateway,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SessionNotFoundException
     * @throws SessionNotRunningException
     */
    public function execute(string $sessionId, string $actorId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            throw new SessionNotFoundException($sessionId);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            throw new SessionNotRunningException($sessionId);
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
            $actorId,
            'force_end',
            null,
            $now,
        );
        $this->auditLogs->persist($log);
        $this->auditLogs->flush();

        return $session->payload();
    }
}
