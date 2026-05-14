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
final readonly class SessionPausedWithoutSaveHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    public function __invoke(SessionPausedWithoutSaveMessage $message): void
    {
        $body = <<<TEXT
[ArchiLAN - alerte automatique]

La session {$message->sessionId} a été mise en pause automatiquement par le watchdog d'inactivité, mais la sauvegarde Archipelago a échoué ou n'a pas pu être uploadée.

Aucune progression n'a été conservée pour cette session.

Veuillez vérifier les logs du runner pour plus de détails.
TEXT;

        $email = (new Email())
            ->from(new Address($this->mailerSender, 'ArchiLAN'))
            ->to(new Address($this->mailerSender, 'Admin ArchiLAN'))
            ->subject("[ArchiLAN] Session {$message->sessionId} - sauvegarde échouée à la mise en pause")
            ->text($body);

        try {
            $this->mailer->send($email);
            $this->logger->info('session.paused_without_save.email_sent', ['sessionId' => $message->sessionId]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('session.paused_without_save.email_failed', [
                'sessionId' => $message->sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
