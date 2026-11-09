<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 52.5: what the Passeur opened in a player's world while they were away, and what it brought them - until
 * they close the notice. `kind` is the pelles of the shares (`event` silver or `bronze`), null when empty.
 */
final readonly class PasseurNotice
{
    /**
     * @param list<PasseurOpeningRow> $openings
     */
    public function __construct(
        public array $openings,
        public int $total,
        public ?string $kind,
    ) {
    }
}
