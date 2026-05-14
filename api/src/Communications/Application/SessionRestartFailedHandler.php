<?php

declare(strict_types=1);

namespace App\Communications\Application;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class SessionRestartFailedHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    public function __invoke(SessionRestartFailedMessage $message): void
    {
        $body = <<<TEXT
[ArchiLAN - alerte automatique]

La session {$message->sessionId} n'a pas pu redemarrer automatiquement apres une tentative de wake-on-connect.

La session a ete remise en pause. Veuillez verifier les logs du bridge et du runner avant de relancer la partie.
TEXT;

        $email = (new Email())
            ->from(new Address($this->mailerSender, 'ArchiLAN'))
            ->to(new Address($this->mailerSender, 'Admin ArchiLAN'))
            ->subject("[ArchiLAN] Session {$message->sessionId} - redemarrage echoue")
            ->text($body);

        try {
            $this->mailer->send($email);
            $this->logger->info('session.restart_failed.email_sent', ['sessionId' => $message->sessionId]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('session.restart_failed.email_failed', [
                'sessionId' => $message->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
