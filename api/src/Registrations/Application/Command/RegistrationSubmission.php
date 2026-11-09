<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Application\Support\RegistrationConfirmationNotice;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class RegistrationSubmission
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private RegistrationConfirmationNotice $confirmationNotice,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Validates and confirms a registration after the registrant has reviewed their selection.
     *
     * @throws NotFoundException   when the registration is missing or not the caller's reserved one
     * @throws ValidationException when the game selection is incomplete
     */
    public function submit(string $registrationId, string $userId): SubmissionResult
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration) {
            throw new NotFoundException('Inscription introuvable.');
        }

        if ($registration->getUserId() !== $userId || !$registration->isReserved()) {
            throw new NotFoundException('Inscription introuvable.');
        }

        $event = $this->eventRepository->findById($registration->getEventId());

        if (null === $event) {
            throw new NotFoundException('Inscription introuvable.');
        }

        if ($event->isGameSelectionEnabled()) {
            $this->validateGameSelection($registration);
        }

        $alreadyConfirmed = null !== $registration->getSubmittedAt();

        if (!$alreadyConfirmed) {
            $now = $this->clock->now();
            $registration->confirm($now);
            $this->registrationRepository->flush();

            $this->logger->info('registration.confirmed', ['registrationId' => $registrationId, 'userId' => $userId]);

            $this->confirmationNotice->send($registration, $event);
        }

        return new SubmissionResult(
            $registration->getId(),
            $event->getTitle(),
            $registration->getSelectedGameIds(),
        );
    }

    /**
     * @throws ValidationException when the registration has no game slots
     */
    private function validateGameSelection(Registration $registration): void
    {
        if ([] === $registration->getGameSlots()) {
            throw new ValidationException('Tu dois selectionner au moins un jeu avant de confirmer.', [], 'games_required');
        }
    }
}
