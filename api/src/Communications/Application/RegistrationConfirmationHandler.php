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
final readonly class RegistrationConfirmationHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    public function __invoke(RegistrationConfirmationMessage $message): void
    {
        $recipientName = $message->userDisplayName ?? $message->userEmail;

        $gameList = [] === $message->selectedGameNames
            ? 'Aucun jeu sélectionné.'
            : implode("\n", array_map(
                static fn (string $name): string => '  - '.$name,
                $message->selectedGameNames,
            ));

        $body = <<<TEXT
Bonjour {$recipientName},

Ton inscription à l'événement "{$message->eventTitle}" a bien été confirmée !

📅 Date : {$message->eventStartsAt}
📍 Lieu : {$message->eventVenue}

🎮 Jeux sélectionnés :
{$gameList}

Retrouve tous les détails sur le site ArchiLAN.

À très bientôt !
L'équipe ArchiLAN
TEXT;

        $email = (new Email())
            ->from(new Address($this->mailerSender, 'ArchiLAN'))
            ->to(new Address($message->userEmail, $recipientName))
            ->subject("Confirmation d'inscription - {$message->eventTitle}")
            ->text($body);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Registration confirmation email failed to send.', [
                'recipient' => $message->userEmail,
                'event' => $message->eventTitle,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
