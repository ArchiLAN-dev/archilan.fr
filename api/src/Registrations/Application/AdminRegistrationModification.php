<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminRegistrationModification
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Atomically replaces the slot list for a registration.
     * Input: { slots: [{gameId: string}] }.
     *
     * @param array<string, mixed> $input
     *
     * @return array{outcome: 'updated', slots: list<array{slotId: string, slotOrder: int, gameId: string}>}
     *                                                                                                       |array{outcome: 'not_found'}
     *                                                                                                       |array{outcome: 'inactive'}
     *                                                                                                       |array{outcome: 'error', errors: array<string, list<string>>}
     */
    public function update(string $eventId, string $registrationId, array $input): array
    {
        $registration = $this->entityManager->find(Registration::class, $registrationId);
        $event = $this->entityManager->find(Event::class, $eventId);

        if (!$registration instanceof Registration || !$event instanceof Event || $registration->getEventId() !== $eventId) {
            return ['outcome' => 'not_found'];
        }

        if (!$registration->isReserved()) {
            return ['outcome' => 'inactive'];
        }

        if (!$event->isGameSelectionEnabled()) {
            return ['outcome' => 'error', 'errors' => ['gameSelection' => ['La sélection de jeux n\'est pas activée pour cet événement.']]];
        }

        if (!array_key_exists('slots', $input)) {
            return ['outcome' => 'error', 'errors' => ['registration' => ['Aucun champ modifiable fourni.']]];
        }

        $slotsInput = $this->parseSlotsInput($input['slots'] ?? null);
        $gameIds = array_column($slotsInput, 'gameId');
        $errors = $this->validateGameIds($gameIds, $event);

        if ([] !== $errors) {
            return ['outcome' => 'error', 'errors' => $errors];
        }

        $now = new \DateTimeImmutable();

        $diffedSlots = $this->diffSlots($registration->getGameSlots(), $slotsInput);
        $registration->replaceSlots($diffedSlots, $now);

        $this->entityManager->flush();

        $this->logger->info('registration.admin_updated', ['registrationId' => $registrationId, 'eventId' => $eventId]);

        return ['outcome' => 'updated', 'slots' => $registration->getGameSlots()];
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
            if (!empty($existingByGameId[$gameId])) {
                $matched = array_shift($existingByGameId[$gameId]);
                $result[] = ['slotId' => $matched['slotId'], 'gameId' => $gameId];
            } else {
                $result[] = ['slotId' => bin2hex(random_bytes(8)), 'gameId' => $gameId];
            }
        }

        return $result;
    }
}
