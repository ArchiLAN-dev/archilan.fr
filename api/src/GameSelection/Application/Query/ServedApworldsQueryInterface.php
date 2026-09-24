<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

/**
 * Every game that serves an apworld, with its hash (story 38.1). A narrow read for the incident
 * reconciliation, which runs every five minutes and must not hydrate the whole catalogue.
 */
interface ServedApworldsQueryInterface
{
    /**
     * @return list<ServedApworld>
     */
    public function servedApworlds(): array;
}
