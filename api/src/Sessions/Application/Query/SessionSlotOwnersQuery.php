<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;

/**
 * Who owns each slot of a session, keyed by the Archipelago slot name.
 *
 * Nothing the bridge publishes carries a member pseudo: its state names a slot by the name the
 * generator gave it, which is the player's yaml `name:` when they set one and only otherwise
 * derives from their pseudo (SlotNameGenerator). So the goal celebration had no way to name the
 * player a slot belongs to, and used the pseudo of whoever was watching instead.
 *
 * The slot name is the join key the rest of the code already uses between Archipelago and the
 * database (RecordSlotGoal, RunResultsQuery), so it is the one exposed here too.
 */
final readonly class SessionSlotOwnersQuery
{
    public function __construct(
        private SessionSlotRepositoryInterface $slots,
        private RunRepositoryInterface $runs,
        private RegistrationRepositoryInterface $registrations,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return list<array{slotName: string, playerName: string}> `playerName` is empty when the owner
     *                                                           cannot be resolved (deleted account)
     */
    public function execute(string $sessionId): array
    {
        $slots = $this->slots->findBySessionId($sessionId);
        if ([] === $slots) {
            return [];
        }

        $userIdByOwnerKey = $this->userIdByOwnerKey($sessionId, $slots);

        // The community pseudo, falling back to the account display name - namesFor() resolves both,
        // and unlike cards() it does not require a public profile, so a slot is named whoever holds it.
        $names = $this->directory->namesFor(array_values(array_unique(array_values($userIdByOwnerKey))));

        $rows = [];
        foreach ($slots as $slot) {
            $userId = $userIdByOwnerKey[$slot->getRegistrationId()] ?? null;
            $rows[] = [
                'slotName' => $slot->getSlotName(),
                'playerName' => null !== $userId ? ($names[$userId] ?? '') : '',
            ];
        }

        return $rows;
    }

    /**
     * SessionSlot.registrationId holds the participant userId on a personal-run session
     * (LaunchPersonalRunJobHandler) and the registration id on an event session.
     *
     * @param list<SessionSlot> $slots
     *
     * @return array<string, string> registrationId => userId
     */
    private function userIdByOwnerKey(string $sessionId, array $slots): array
    {
        $map = [];

        if ($this->runs->findBySessionId($sessionId) instanceof Run) {
            foreach ($slots as $slot) {
                $map[$slot->getRegistrationId()] = $slot->getRegistrationId();
            }

            return $map;
        }

        $registrationIds = array_values(array_unique(array_map(
            static fn (SessionSlot $slot): string => $slot->getRegistrationId(),
            $slots,
        )));

        /** @var list<Registration> $registrations */
        $registrations = $this->registrations->findBy(['id' => $registrationIds]);

        foreach ($registrations as $registration) {
            $map[$registration->getId()] = $registration->getUserId();
        }

        return $map;
    }
}
