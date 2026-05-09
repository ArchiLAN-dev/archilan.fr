<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationAdminMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class SendMessageToRegistrant
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $mailerSender,
    ) {
    }

    /**
     * @return array{outcome: 'not_found'}|array{outcome: 'send_failed'}|array{outcome: 'sent', sentAt: string}
     */
    public function send(string $eventId, string $registrationId, string $adminId, string $subject, string $body): array
    {
        $registration = $this->entityManager->find(Registration::class, $registrationId);

        if (!$registration instanceof Registration || $registration->getEventId() !== $eventId) {
            return ['outcome' => 'not_found'];
        }

        $participant = $this->entityManager->find(User::class, $registration->getUserId());

        if (!$participant instanceof User) {
            return ['outcome' => 'not_found'];
        }

        $emailBody = $body."\n\n---\nCe message a été envoyé depuis le backoffice ArchiLAN.";

        $email = (new Email())
            ->from(new Address($this->mailerSender, 'ArchiLAN'))
            ->to(new Address($participant->getEmail(), $participant->getDisplayName() ?? $participant->getEmail()))
            ->subject($subject)
            ->text($emailBody);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('admin.registrations.message_send_failed', [
                'registrationId' => $registrationId,
                'eventId' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return ['outcome' => 'send_failed'];
        }

        $sentAt = new \DateTimeImmutable();
        $this->entityManager->persist(RegistrationAdminMessage::record($eventId, $registrationId, $adminId, $subject, $sentAt));
        $this->entityManager->flush();

        return ['outcome' => 'sent', 'sentAt' => $sentAt->format(\DateTimeInterface::ATOM)];
    }
}
