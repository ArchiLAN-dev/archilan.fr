<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Application\Query\PendingRegistrationSurveyQueryInterface;
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
        private PendingRegistrationSurveyQueryInterface $pendingSurveys,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Validates and confirms a registration after the registrant has reviewed their selection.
     *
     * @throws NotFoundException   when the registration is missing or not the caller's reserved one
     * @throws ValidationException when the game selection is incomplete, or a required question of the organisers
     *                             is unanswered (story 51.5)
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
            // Story 51.5: a confirmed registration is never blocked again; it hears of a later survey otherwise.
            if ($this->pendingSurveys->hasUnansweredRequired($event->getId(), $userId, $now)) {
                throw new ValidationException('Réponds d\'abord aux questions obligatoires de l\'orga.', [], 'registration_survey_incomplete');
            }
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
