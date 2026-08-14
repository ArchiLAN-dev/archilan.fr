<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Entity\RunAuditLog;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\RunAuditLogRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Support\BridgeEndpoint;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SendBridgeCommand
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private RunAuditLogRepositoryInterface $auditLogs,
        private SessionSlotRepositoryInterface $slots,
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException           when the session does not exist
     * @throws ConflictException           when the session is not running
     * @throws ServiceUnavailableException when the bridge cannot be reached
     */
    public function execute(string $sessionId, string $command, string $actorId): void
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            throw new NotFoundException('Session introuvable.');
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            throw new ConflictException('La session n\'est pas en cours.', 'session_not_running');
        }

        // Le port hôte n'est plus une adresse : il reste le marqueur qu'un bridge a été lancé
        // pour cette session (story 37.7).
        $bridgePort = $session->getBridgePort();

        if (null === $bridgePort) {
            throw new ServiceUnavailableException('Bridge non disponible.', 'bridge_unavailable');
        }

        $adminPassword = $session->getAdminPassword();

        try {
            $bridgeResponse = $this->httpClient->request(
                'POST',
                BridgeEndpoint::url($sessionId, '/commands'),
                [
                    'json' => ['command' => $command],
                    'headers' => null !== $adminPassword ? ['X-Ap-Admin-Password' => $adminPassword] : [],
                    'timeout' => 3,
                ],
            );
            $bridgeStatus = $bridgeResponse->getStatusCode();
            if ($bridgeStatus < 200 || $bridgeStatus >= 300) {
                throw new ServiceUnavailableException('Bridge non disponible.', 'bridge_unavailable');
            }
        } catch (ServiceUnavailableException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ServiceUnavailableException('Bridge non disponible.', 'bridge_unavailable');
        }

        $log = new RunAuditLog(
            bin2hex(random_bytes(16)),
            $sessionId,
            $actorId,
            'command',
            ['command' => $command],
            $this->clock->now(),
        );
        $this->auditLogs->persist($log);

        $this->maybeMarkSlotReleased($sessionId, $command);

        $this->auditLogs->flush();
    }

    private function maybeMarkSlotReleased(string $sessionId, string $command): void
    {
        if (1 !== preg_match('/^!admin\s+\/(collect|release|forfeit)\s+(\S+)/i', $command, $matches)) {
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
