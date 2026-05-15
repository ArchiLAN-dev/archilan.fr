<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SendBridgeCommand
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{found: bool, error: string|null}
     */
    public function execute(string $sessionId, string $command, string $actorId): array
    {
        try {
            $session = $this->findOrFail(Session::class, $sessionId);
        } catch (\RuntimeException) {
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

        try {
            $bridgeResponse = $this->httpClient->request(
                'POST',
                sprintf('http://%s:%d/commands', $host, $bridgePort),
                ['json' => ['command' => $command], 'timeout' => 3],
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
        $this->entityManager->persist($log);

        $this->maybeMarkSlotReleased($sessionId, $command);

        $this->entityManager->flush();

        return ['found' => true, 'error' => null];
    }

    private function maybeMarkSlotReleased(string $sessionId, string $command): void
    {
        if (!preg_match('/^!admin\s+\/(collect|release|forfeit)\s+(\S+)/i', $command, $matches)) {
            return;
        }

        $slotName = $matches[2];

        /** @var SessionSlot|null $slot */
        $slot = $this->entityManager->getRepository(SessionSlot::class)->findOneBy([
            'sessionId' => $sessionId,
            'slotName' => $slotName,
        ]);

        if (null === $slot) {
            return;
        }

        $slot->markAsReleased();
    }
}
