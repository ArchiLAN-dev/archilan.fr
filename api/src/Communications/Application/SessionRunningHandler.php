<?php

declare(strict_types=1);

namespace App\Communications\Application;

use App\Communications\Application\Email\SessionRunningEmail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SessionRunningHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private HubInterface $mercureHub,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SessionRunningMessage $message): void
    {
        $this->publishMercureNotification($message);

        $this->mailer->send(new SessionRunningEmail(
            $message->userEmail,
            $message->userDisplayName,
            $message->eventTitle,
            $message->host,
            $message->port,
            $message->password,
            $message->slotNames,
        ));
    }

    private function publishMercureNotification(SessionRunningMessage $message): void
    {
        try {
            $payload = [
                'type' => 'session.running',
                'sessionId' => $message->sessionId,
                'host' => $message->host,
                'port' => $message->port,
                'password' => $message->password,
                'slotNames' => $message->slotNames,
            ];

            $this->mercureHub->publish(new Update(
                sprintf('/users/%s/session-alerts', $message->userId),
                json_encode($payload, JSON_THROW_ON_ERROR),
                true,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('session.running.mercure_failed', [
                'sessionId' => $message->sessionId,
                'userId' => $message->userId,
                'exception' => $e,
            ]);
        }
    }
}
