<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Communications\Application\ArchilanMailer;
use App\Communications\Application\Email\AdminDirectMessageEmail;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationAdminMessage;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SendMessageToRegistrant
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArchilanMailer $mailer,
    ) {
    }

    /**
     * @return array{outcome: 'not_found'}|array{outcome: 'send_failed'}|array{outcome: 'sent', sentAt: string}
     */
    public function send(string $eventId, string $registrationId, string $adminId, string $subject, string $body): array
    {
        try {
            $registration = $this->findOrFail(Registration::class, $registrationId);
        } catch (\RuntimeException) {
            return ['outcome' => 'not_found'];
        }

        if ($registration->getEventId() !== $eventId) {
            return ['outcome' => 'not_found'];
        }

        try {
            $participant = $this->findOrFail(User::class, $registration->getUserId());
        } catch (\RuntimeException) {
            return ['outcome' => 'not_found'];
        }

        $sent = $this->mailer->send(new AdminDirectMessageEmail(
            $participant->getEmail(),
            $participant->getDisplayName(),
            $subject,
            $body,
        ));

        if (!$sent) {
            return ['outcome' => 'send_failed'];
        }

        $sentAt = new \DateTimeImmutable();
        $this->entityManager->persist(RegistrationAdminMessage::record($eventId, $registrationId, $adminId, $subject, $sentAt));
        $this->entityManager->flush();

        return ['outcome' => 'sent', 'sentAt' => $sentAt->format(\DateTimeInterface::ATOM)];
    }
}
