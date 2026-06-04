<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\RunAuditLogRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SendBridgeCommand
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private RunAuditLogRepositoryInterface $auditLogs,
        private SessionSlotRepositoryInterface $slots,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{found: bool, error: string|null}
     */
    public function execute(string $sessionId, string $command, string $actorId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false, 'error' => null];
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return ['found' => true, 'error' => 'session_not_running'];
        }

        $host = $session->getHost();
        $bridgePort = $session->getBridgePort();

        if (null === $host || null === $bridgePort) {
            return ['found' => true, 'error' => 'bridge_unavailable'];
        }

        $adminPassword = $session->getAdminPassword();

        try {
            $bridgeResponse = $this->httpClient->request(
                'POST',
                sprintf('http://%s:%d/commands', $host, $bridgePort),
                [
                    'json' => ['command' => $command],
                    'headers' => null !== $adminPassword ? ['X-Ap-Admin-Password' => $adminPassword] : [],
                    'timeout' => 3,
                ],
            );
            $bridgeStatus = $bridgeResponse->getStatusCode();
            if ($bridgeStatus < 200 || $bridgeStatus >= 300) {
                return ['found' => true, 'error' => 'bridge_unavailable'];
            }
        } catch (\Throwable) {
            return ['found' => true, 'error' => 'bridge_unavailable'];
        }

        $log = new RunAuditLog(
            bin2hex(random_bytes(16)),
            $sessionId,
            $actorId,
            'command',
            ['command' => $command],
            new \DateTimeImmutable(),
        );
        $this->auditLogs->persist($log);

        $this->maybeMarkSlotReleased($sessionId, $command);

        $this->auditLogs->flush();

        return ['found' => true, 'error' => null];
    }

    private function maybeMarkSlotReleased(string $sessionId, string $command): void
    {
        if (!preg_match('/^!admin\s+\/(collect|release|forfeit)\s+(\S+)/i', $command, $matches)) {
            return;
        }

        $slotName = $matches[2];

        $slot = $this->slots->findBySessionAndSlotName($sessionId, $slotName);

        if (!$slot instanceof SessionSlot) {
            return;
        }

        $slot->markAsReleased();
    }
}
