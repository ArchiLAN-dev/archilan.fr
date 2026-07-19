<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Communications\Application\Email\AdminDirectMessageEmail;
use App\Communications\Application\Service\ArchilanMailer;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Registrations\Domain\Entity\RegistrationAdminMessage;
use App\Registrations\Domain\Repository\RegistrationAdminMessageRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\BadGatewayException;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Clock\ClockInterface;

final readonly class SendMessageToRegistrant
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private UserRepositoryInterface $userRepository,
        private RegistrationAdminMessageRepositoryInterface $adminMessageRepository,
        private ArchilanMailer $mailer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the send timestamp (ATOM)
     *
     * @throws NotFoundException   when the registration (for this event/participant) does not exist
     * @throws BadGatewayException when the mailer refuses to send
     */
    public function send(string $eventId, string $registrationId, string $adminId, string $subject, string $body): string
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration) {
            throw new NotFoundException('Inscription introuvable.');
        }

        if ($registration->getEventId() !== $eventId) {
            throw new NotFoundException('Inscription introuvable.');
        }

        $participant = $this->userRepository->findById($registration->getUserId());

        if (null === $participant) {
            throw new NotFoundException('Inscription introuvable.');
        }

        $sent = $this->mailer->send(new AdminDirectMessageEmail(
            $participant->getEmail(),
            $participant->getDisplayName(),
            $subject,
            $body,
        ));

        if (!$sent) {
            throw new BadGatewayException('L\'envoi du message a échoué.', 'message_send_failed');
        }

        $sentAt = $this->clock->now();
        $this->adminMessageRepository->save(RegistrationAdminMessage::record($eventId, $registrationId, $adminId, $subject, $sentAt));

        return $sentAt->format(\DateTimeInterface::ATOM);
    }
}
