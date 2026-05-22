<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Communications\Application\ArchilanMailer;
use App\Communications\Application\Email\AdminDirectMessageEmail;
use App\Identity\Domain\UserRepositoryInterface;
use App\Registrations\Domain\RegistrationAdminMessage;
use App\Registrations\Domain\RegistrationAdminMessageRepositoryInterface;
use App\Registrations\Domain\RegistrationRepositoryInterface;

final readonly class SendMessageToRegistrant
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private UserRepositoryInterface $userRepository,
        private RegistrationAdminMessageRepositoryInterface $adminMessageRepository,
        private ArchilanMailer $mailer,
    ) {
    }

    /**
     * @return array{outcome: 'not_found'}|array{outcome: 'send_failed'}|array{outcome: 'sent', sentAt: string}
     */
    public function send(string $eventId, string $registrationId, string $adminId, string $subject, string $body): array
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration) {
            return ['outcome' => 'not_found'];
        }

        if ($registration->getEventId() !== $eventId) {
            return ['outcome' => 'not_found'];
        }

        $participant = $this->userRepository->findById($registration->getUserId());

        if (null === $participant) {
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
        $this->adminMessageRepository->save(RegistrationAdminMessage::record($eventId, $registrationId, $adminId, $subject, $sentAt));

        return ['outcome' => 'sent', 'sentAt' => $sentAt->format(\DateTimeInterface::ATOM)];
    }
}
