<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Service;

use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * Who of a private run can say they are ready, and how many are (story 16.23).
 *
 * A participant can be ready once they have a game, or play someone else's slot as a co-player: a co-player
 * has nothing to choose but still has to be there. The others are left out of the count, so a participant who
 * only came to look never keeps "3 / 4" from becoming "everyone is ready".
 */
final class RunReadiness
{
    /**
     * @param list<RunParticipant> $participants
     * @param list<string>         $coPlayerUserIds the members playing a slot of this run as co-players
     *
     * @return array<string, true> the user ids that can say they are ready
     */
    public static function eligible(array $participants, array $coPlayerUserIds): array
    {
        $coPlaying = array_fill_keys($coPlayerUserIds, true);
        $eligible = [];
        foreach ($participants as $participant) {
            if ($participant->hasSlots() || isset($coPlaying[$participant->getUserId()])) {
                $eligible[$participant->getUserId()] = true;
            }
        }

        return $eligible;
    }

    /**
     * @param list<RunParticipant> $participants
     * @param array<string, true>  $eligible     from {@see eligible()}
     *
     * @return array{ready: int, total: int}
     */
    public static function count(array $participants, array $eligible): array
    {
        $ready = 0;
        foreach ($participants as $participant) {
            if (isset($eligible[$participant->getUserId()]) && $participant->isReady()) {
                ++$ready;
            }
        }

        return ['ready' => $ready, 'total' => count($eligible)];
    }
}
