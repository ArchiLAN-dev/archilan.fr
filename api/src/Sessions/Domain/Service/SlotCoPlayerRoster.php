<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Service;

/**
 * The rules that decide who may be a co-player of a slot (story 16.17).
 *
 * Pure: it is handed the owner, the requested list and the set of people attached to the party, and
 * it returns the roster to store plus what it refused. No repository, no clock - so every rule is
 * unit-testable without a database.
 */
final readonly class SlotCoPlayerRoster
{
    public const string ERROR_OWNER = 'owner';
    public const string ERROR_NOT_A_PARTICIPANT = 'not_a_participant';

    /**
     * Resolve the roster to persist for a slot.
     *
     * Duplicates collapse silently - asking twice for the same person is not a mistake worth an
     * error - but the owner and outsiders are reported, because both mean the caller believes
     * something about the party that is not true.
     *
     * @param string       $ownerUserId  the member who declared the slot and its configuration
     * @param list<string> $requested    the co-players the caller wants on the slot
     * @param list<string> $participants every member attached to the party, owner included
     *
     * @return array{userIds: list<string>, errors: list<string>}
     */
    public function resolve(string $ownerUserId, array $requested, array $participants): array
    {
        $userIds = [];
        $errors = [];

        foreach ($requested as $userId) {
            if ('' === $userId || in_array($userId, $userIds, true)) {
                continue;
            }

            // Being co-player of a slot one already owns would double-count its checks.
            if ($userId === $ownerUserId) {
                $errors[] = self::ERROR_OWNER;
                continue;
            }

            if (!in_array($userId, $participants, true)) {
                $errors[] = self::ERROR_NOT_A_PARTICIPANT;
                continue;
            }

            $userIds[] = $userId;
        }

        return ['userIds' => $userIds, 'errors' => array_values(array_unique($errors))];
    }
}
