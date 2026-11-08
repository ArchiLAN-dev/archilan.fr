<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Registrations\Application\Support\SlotYamlName;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * An admin saves the YAML of a registration's slot on the player's behalf (story 5.9): the player's checks
 * (apworld ready, valid slot name), without the owner check and whatever the registration window.
 */
final readonly class AdminSlotYamlModification
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private GameRepositoryInterface $gameRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException   when the registration (for this event) or the slot does not exist
     * @throws ConflictException   when the registration is cancelled
     * @throws ValidationException when the game has no apworld or the slot name is invalid
     */
    public function save(string $eventId, string $registrationId, string $slotId, string $adminId, string $playerYaml): void
    {
        $registration = $this->registrationRepository->findById($registrationId);
        if (null === $registration || $registration->getEventId() !== $eventId) {
            $this->auditLog($eventId, $registrationId, $slotId, $adminId, 'not_found');

            throw new NotFoundException('Inscription introuvable.');
        }

        if (!$registration->isReserved()) {
            $this->auditLog($eventId, $registrationId, $slotId, $adminId, 'inactive');

            throw new ConflictException('L\'inscription n\'est plus modifiable.', 'inactive_registration');
        }

        $slot = $registration->getSlot($slotId);
        $game = null === $slot ? null : $this->gameRepository->findById($slot['gameId']);
        if (null === $game) {
            $this->auditLog($eventId, $registrationId, $slotId, $adminId, 'not_found');

            throw new NotFoundException('Slot introuvable.');
        }

        if (!$game->isApworldReady()) {
            $this->auditLog($eventId, $registrationId, $slotId, $adminId, 'error');

            throw new ValidationException('Le YAML contient des erreurs.', ['game' => ['Ce jeu n\'a pas encore de fichier .apworld configuré.']]);
        }

        $nameError = SlotYamlName::error($playerYaml);
        if (null !== $nameError) {
            $this->auditLog($eventId, $registrationId, $slotId, $adminId, 'error');

            throw new ValidationException('Le YAML contient des erreurs.', ['name' => [$nameError]]);
        }

        $registration->submitSlotPlayerYaml($slotId, $playerYaml, $game->getApworldHash() ?? '', $this->clock->now());
        $this->registrationRepository->flush();

        $this->auditLog($eventId, $registrationId, $slotId, $adminId, 'updated');
    }

    private function auditLog(string $eventId, string $registrationId, string $slotId, string $adminId, string $outcome): void
    {
        $this->logger->info('admin.registrations.slot_yaml_update', [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'slotId' => $slotId,
            'adminId' => $adminId,
            'outcome' => $outcome,
            'occurredAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
