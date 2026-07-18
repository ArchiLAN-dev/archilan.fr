<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Domain\Entity\RunAuditLog;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Exception\SessionNotFoundException;
use App\Sessions\Domain\Exception\SessionNotRunningException;
use App\Sessions\Domain\Repository\RunAuditLogRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\ValueObject\SessionView;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ForceEndSessionCommand
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private RunAuditLogRepositoryInterface $auditLogs,
        private MessageBusInterface $messageBus,
        private RunnerGatewayInterface $runnerGateway,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SessionNotFoundException
     * @throws SessionNotRunningException
     */
    public function execute(string $sessionId, string $actorId): SessionView
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            throw new SessionNotFoundException($sessionId);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            throw new SessionNotRunningException($sessionId);
        }

        $now = $this->clock->now();
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
