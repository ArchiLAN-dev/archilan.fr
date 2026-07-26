<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Counts the recap superlatives a player won across finished sessions of public events
 * (story 32.4). Feeds the `superlative:{key}` achievement facts.
 */
interface RecapSuperlativesQueryInterface
{
    /**
     * @return array<string, int> superlative key (e.g. "most_generous") => times won
     */
    public function superlativeCountsFor(string $userId): array;
}
