<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Story 30.49: the items a member received from the other players, for the achievement facts.
 */
interface ItemsFromOthersQueryInterface
{
    /**
     * Items received in a slot the member plays, found in a slot the member does not play (neither the same slot nor
     * another of theirs), leaving out what a release or a collect handed over.
     */
    public function count(string $userId): int;
}
