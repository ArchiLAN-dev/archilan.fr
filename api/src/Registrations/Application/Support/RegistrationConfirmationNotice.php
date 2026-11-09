<?php

declare(strict_types=1);

namespace App\Registrations\Application\Support;

use App\Communications\Application\Message\RegistrationConfirmationMessage;
use App\Events\Domain\Entity\Event;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The confirmation e-mail a member gets once their registration is confirmed, by themselves or by an admin
 * (story 5.10). Dispatched after the write.
 */
final readonly class RegistrationConfirmationNotice
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private GameRepositoryInterface $gameRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function send(Registration $registration, Event $event): void
    {
        $userId = $registration->getUserId();
        $user = $this->userRepository->findById($userId);

        $this->messageBus->dispatch(new RegistrationConfirmationMessage(
            userEmail: $user instanceof User ? $user->getEmail() : $userId,
            userDisplayName: $user instanceof User ? $user->getDisplayName() : null,
            eventTitle: $event->getTitle(),
            // Story 4.22: Paris time, and an escaped "à" ('a' alone printed am/pm).
            eventStartsAt: $event->getStartsAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y \à H\hi'),
            eventVenue: $event->getVenue(),
            selectedGameNames: $this->resolveGameNames($registration->getSelectedGameIds()),
            eventId: $event->getId(),
            registrationId: $registration->getId(),
        ));
    }

    /**
     * @param list<string> $gameIds ordered list (may contain duplicates)
     *
     * @return list<string>
     */
    private function resolveGameNames(array $gameIds): array
    {
        if ([] === $gameIds) {
            return [];
        }

        /** @var list<string> $uniqueIds */
        $uniqueIds = array_values(array_unique($gameIds));
        $games = $this->gameRepository->findByIds($uniqueIds);

        /** @var array<string, string> $namesById */
        $namesById = [];
        foreach ($games as $game) {
            $namesById[$game->getId()] = $game->getName();
        }

        $names = [];
        foreach ($gameIds as $gameId) {
            $name = $namesById[$gameId] ?? null;
            if (null !== $name && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
