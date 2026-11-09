<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Application\Exception\WarningsToAcknowledgeException;
use App\Registrations\Application\Support\AdminRegistrationWarning;
use App\Registrations\Application\Support\RegistrationConfirmationNotice;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 5.10: an admin confirms a registration on the member's behalf. A slot still without YAML is a warning; no
 * game at all still blocks, as for the member, since there would be nothing to generate.
 */
final readonly class AdminRegistrationConfirmation
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
     * @throws NotFoundException              when the registration does not exist for this event
     * @throws ConflictException              when it is cancelled or already confirmed
     * @throws ValidationException            when it has no game while the event has a game selection
     * @throws WarningsToAcknowledgeException when a slot has no YAML and the admin has not acknowledged it
     */
    public function confirm(string $eventId, string $registrationId, string $adminId, bool $sendEmail, bool $acknowledgeWarnings): void
    {
        $registration = $this->registrationRepository->findById($registrationId);
        $event = $this->eventRepository->findById($eventId);

        if (null === $registration || null === $event || $registration->getEventId() !== $eventId) {
            $this->auditLog($eventId, $registrationId, $adminId, 'not_found', []);

            throw new NotFoundException('Inscription introuvable.');
        }

        if (!$registration->isReserved()) {
            $this->auditLog($eventId, $registrationId, $adminId, 'inactive', []);

            throw new ConflictException('L\'inscription est annulée : réactive-la d\'abord.', 'inactive_registration');
        }

        if (null !== $registration->getSubmittedAt()) {
            $this->auditLog($eventId, $registrationId, $adminId, 'already_confirmed', []);

            throw new ConflictException('L\'inscription est déjà confirmée.', 'already_confirmed');
        }

        $warnings = [];
        if ($event->isGameSelectionEnabled()) {
            if ([] === $registration->getGameSlots()) {
                $this->auditLog($eventId, $registrationId, $adminId, 'games_required', []);

                throw new ValidationException('Il faut au moins un jeu pour confirmer l\'inscription.', [], 'games_required');
            }
            if ($this->hasSlotWithoutYaml($registration)) {
                $warnings[] = AdminRegistrationWarning::IncompleteYaml;
            }
        }

        if (!$acknowledgeWarnings && [] !== $warnings) {
            $this->auditLog($eventId, $registrationId, $adminId, 'warnings_to_acknowledge', []);
        }
        WarningsToAcknowledgeException::unlessAcknowledged($warnings, $acknowledgeWarnings);

        $registration->confirmByAdmin($adminId, $this->clock->now());
        $this->registrationRepository->flush();

        $this->auditLog($eventId, $registrationId, $adminId, 'confirmed', $warnings);

        if ($sendEmail) {
            try {
                $this->confirmationNotice->send($registration, $event);
            } catch (\Throwable $e) {
                // The confirmation stands without its e-mail: a failing transport never undoes the write.
                $this->logger->warning('admin.registrations.confirm_email_failed', ['registrationId' => $registrationId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function hasSlotWithoutYaml(Registration $registration): bool
    {
        foreach ($registration->getGameSlots() as $slot) {
            $yaml = $slot['playerYaml'] ?? null;
            if (null === $yaml || '' === trim($yaml)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<AdminRegistrationWarning> $acknowledged
     */
    private function auditLog(string $eventId, string $registrationId, string $adminId, string $outcome, array $acknowledged): void
    {
        $this->logger->info('admin.registrations.confirm', [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'adminId' => $adminId,
            'outcome' => $outcome,
            'acknowledgedWarnings' => array_map(static fn (AdminRegistrationWarning $w): string => $w->value, $acknowledged),
            'occurredAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
