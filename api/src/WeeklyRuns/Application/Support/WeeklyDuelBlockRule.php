<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Support;

use App\WeeklyRuns\Domain\Entity\WeeklyDuel;
use App\WeeklyRuns\Domain\Entity\WeeklyDuelParticipant;

/**
 * A block between two members of a weekly duel ends the duel for that pair (story 43.15): one of them leaves it.
 * The creator stays when involved, since the duel is theirs; otherwise the blocked member leaves, the blocker keeps
 * their place. Applied whenever the duel is read, answered or resolved, so a block counts from the next look on.
 */
final class WeeklyDuelBlockRule
{
    /**
     * @param list<WeeklyDuelParticipant>       $participants
     * @param list<array{0: string, 1: string}> $blocks       [blockerId, blockedId]
     *
     * @return bool whether a participant was cancelled
     */
    public static function apply(WeeklyDuel $duel, array $participants, array $blocks, \DateTimeImmutable $now): bool
    {
        $active = [];
        foreach ($participants as $participant) {
            if ($participant->isActive()) {
                $active[$participant->getUserId()] = $participant;
            }
        }

        $changed = false;
        foreach ($blocks as [$blocker, $blocked]) {
            if (!isset($active[$blocker], $active[$blocked])) {
                continue;
            }
            $leaving = $duel->isCreatedBy($blocked) ? $blocker : $blocked;
            $active[$leaving]->cancel($now);
            unset($active[$leaving]);
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param list<WeeklyDuelParticipant> $participants
     *
     * @return list<string>
     */
    public static function activeUserIds(array $participants): array
    {
        return array_values(array_map(
            static fn (WeeklyDuelParticipant $p): string => $p->getUserId(),
            array_filter($participants, static fn (WeeklyDuelParticipant $p): bool => $p->isActive()),
        ));
    }
}
