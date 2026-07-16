<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminRegistrationModification
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Atomically replaces the slot list for a registration.
     * Input: { slots: [{gameId: string}] }.
     *
     * @param array<string, mixed> $input
     *
     * @throws NotFoundException   when the registration does not exist for this event
     * @throws ConflictException   when the registration is no longer modifiable
     * @throws ValidationException when the slot list is invalid
     */
    public function update(string $eventId, string $registrationId, string $adminId, array $input): void
    {
        $registration = $this->registrationRepository->findById($registrationId);
        $event = $this->eventRepository->findById($eventId);

        if (null === $registration || null === $event || $registration->getEventId() !== $eventId) {
            $this->auditLog($eventId, $registrationId, $adminId, 'not_found');

            throw new NotFoundException('Inscription introuvable.');
        }

        if (!$registration->isReserved()) {
            $this->auditLog($eventId, $registrationId, $adminId, 'inactive');

            throw new ConflictException('L\'inscription n\'est plus modifiable.', 'inactive_registration');
        }

        if (!$event->isGameSelectionEnabled()) {
            $this->auditLog($eventId, $registrationId, $adminId, 'error');

            throw new ValidationException('La modification contient des erreurs.', ['gameSelection' => ['La sélection de jeux n\'est pas activée pour cet événement.']], 'invalid_registration_update');
        }

        if (!array_key_exists('slots', $input)) {
            $this->auditLog($eventId, $registrationId, $adminId, 'error');

            throw new ValidationException('La modification contient des erreurs.', ['registration' => ['Aucun champ modifiable fourni.']], 'invalid_registration_update');
        }

        $slotsInput = $this->parseSlotsInput($input['slots'] ?? null);
        $gameIds = array_column($slotsInput, 'gameId');
        $errors = $this->validateGameIds($gameIds, $event);

        if ([] !== $errors) {
            $this->auditLog($eventId, $registrationId, $adminId, 'error');

            throw new ValidationException('La modification contient des erreurs.', $errors, 'invalid_registration_update');
        }

        $now = $this->clock->now();

        $diffedSlots = $this->diffSlots($registration->getGameSlots(), $slotsInput);
        $registration->replaceSlots($diffedSlots, $now);

        $this->registrationRepository->flush();

        $this->logger->info('registration.admin_updated', ['registrationId' => $registrationId, 'eventId' => $eventId]);
        $this->auditLog($eventId, $registrationId, $adminId, 'updated');
    }

    private function auditLog(string $eventId, string $registrationId, string $adminId, string $outcome): void
    {
        $this->logger->info('admin.registrations.update', [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'adminId' => $adminId,
            'outcome' => $outcome,
            'occurredAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @return list<array{gameId: string}>
     */
    private function parseSlotsInput(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['gameId']) || !is_string($entry['gameId'])) {
                continue;
            }
            $result[] = ['gameId' => $entry['gameId']];
        }

        return $result;
    }

    /**
     * @param list<string> $gameIds
     *
     * @return array<string, list<string>>
     */
    private function validateGameIds(array $gameIds, Event $event): array
    {
        $errors = [];
        $max = $event->getGameSelectionMaxPerRegistrant();

        if (null !== $max && count($gameIds) > $max) {
            $errors['gameIds'] = [sprintf('La sélection ne peut pas dépasser %d jeu(x).', $max)];

            return $errors;
        }

        /** @var list<string> $availableIds */
        $availableIds = array_column($event->getGameSelectionConfig(), 'gameId');

        foreach ($gameIds as $index => $gameId) {
            if (!in_array($gameId, $availableIds, true)) {
                $errors[sprintf('slots.%d.gameId', $index)] = ['Ce jeu n\'est pas disponible pour cet événement.'];
            }
        }

        return $errors;
    }

    /**
     * Option-preserving slot diffing: reuses existing slotIds when gameId matches.
     *
     * @param list<array{slotId: string, gameId: string, slotOrder: int}> $existingSlots
     * @param list<array{gameId: string}>                                 $slotsInput
     *
     * @return list<array{slotId: string, gameId: string}>
     */
    private function diffSlots(array $existingSlots, array $slotsInput): array
    {
        /** @var array<string, list<array{slotId: string}>> $existingByGameId */
        $existingByGameId = [];
        foreach ($existingSlots as $slot) {
            $existingByGameId[$slot['gameId']][] = ['slotId' => $slot['slotId']];
        }

        $result = [];
        foreach ($slotsInput as $entry) {
            $gameId = $entry['gameId'];
            if ([] !== ($existingByGameId[$gameId] ?? [])) {
                $matched = array_shift($existingByGameId[$gameId]);
                $result[] = ['slotId' => $matched['slotId'], 'gameId' => $gameId];
            } else {
                $result[] = ['slotId' => bin2hex(random_bytes(8)), 'gameId' => $gameId];
            }
        }

        return $result;
    }
}
