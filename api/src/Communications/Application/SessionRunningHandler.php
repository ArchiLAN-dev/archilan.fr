<?php

declare(strict_types=1);

namespace App\Communications\Application;

use App\Shared\Application\Handler\LogsHandlerErrors;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class SessionRunningHandler
{
    use LogsHandlerErrors;

    public function __construct(
        private MailerInterface $mailer,
        private HubInterface $mercureHub,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    public function __invoke(SessionRunningMessage $message): void
    {
        $this->publishMercureNotification($message);
        $this->sendEmail($message);
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
            // Mercure failure must not prevent email delivery — log and continue.
            $this->logger->error('session.running.mercure_failed', [
                'sessionId' => $message->sessionId,
                'userId' => $message->userId,
                'exception' => $e,
            ]);
        }
    }

    private function sendEmail(SessionRunningMessage $message): void
    {
        $recipientName = $message->userDisplayName ?? $message->userEmail;

        $slotList = [] === $message->slotNames
            ? '  (aucun slot assigné)'
            : implode("\n", array_map(
                static fn (string $name): string => "  - {$name}",
                $message->slotNames,
            ));

        $body = <<<TEXT
Bonjour {$recipientName},

Votre session Archipelago pour l'événement "{$message->eventTitle}" est maintenant en cours !

🎮 Vos slots :
{$slotList}

📡 Informations de connexion :
  Hôte         : {$message->host}
  Port         : {$message->port}
  Mot de passe : {$message->password}

Comment se connecter :
1. Lancez votre client Archipelago.
2. Allez dans « Connect » et saisissez les informations ci-dessus.
3. Rejoignez la room et bonne session !

À tout de suite,
L'équipe ArchiLAN
TEXT;

        $email = (new Email())
            ->from(new Address($this->mailerSender, 'ArchiLAN'))
            ->to(new Address($message->userEmail, $recipientName))
            ->subject("Votre session Archipelago est prête - {$message->eventTitle}")
            ->text($body);

        $this->executeWithLogging('session.running.email_failed', function () use ($email, $message): void {
            $this->mailer->send($email);
            $this->logger->info('session.running.email_sent', [
                'sessionId' => $message->sessionId,
                'recipient' => $message->userEmail,
            ]);
        });
    }
}
