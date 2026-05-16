<?php

declare(strict_types=1);

namespace App\Communications\Application;

use App\Communications\Application\Email\SessionRestartFailedEmail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SessionRestartFailedHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    public function __invoke(SessionRestartFailedMessage $message): void
    {
        $sent = $this->mailer->send(new SessionRestartFailedEmail($this->mailerSender, $message->sessionId));

        if ($sent) {
            $this->logger->info('session.restart_failed.email_sent', ['sessionId' => $message->sessionId]);
        }
    }
}
