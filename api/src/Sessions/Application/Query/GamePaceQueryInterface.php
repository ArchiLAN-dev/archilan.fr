<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 56.2: the paces our finished games showed, the raw material of a slot's estimated duration.
 */
interface GamePaceQueryInterface
{
    /**
     * One sample per slot that reached its goal: event and private sessions (checks done over the time from the
     * session's first launch to the goal; a pause is not taken out) and weekly entries (checks over completion time).
     * A slot played by several members carries all of them.
     *
     * @return list<PaceSample>
     */
    public function samples(): array;

    /**
     * The admins' estimates, by game id (games without one are absent).
     *
     * @return array<string, int>
     */
    public function adminEstimates(): array;
}
