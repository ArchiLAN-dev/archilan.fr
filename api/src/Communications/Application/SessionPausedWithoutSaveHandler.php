<?php

declare(strict_types=1);

namespace App\Communications\Application;

use App\Communications\Application\Email\SessionPausedWithoutSaveEmail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SessionPausedWithoutSaveHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    public function __invoke(SessionPausedWithoutSaveMessage $message): void
    {
        $sent = $this->mailer->send(new SessionPausedWithoutSaveEmail($this->mailerSender, $message->sessionId));

        if ($sent) {
            $this->logger->info('session.paused_without_save.email_sent', ['sessionId' => $message->sessionId]);
        }
    }
}
