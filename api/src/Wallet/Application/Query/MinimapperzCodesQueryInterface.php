<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

/**
 * Story 49.2: the Minimapperz codes of an event as the admin sees them.
 *
 * @phpstan-type MinimapperzCodeRow array{code: string, userId: string|null, displayName: string|null, registered: bool, assignedAt: string|null}
 */
interface MinimapperzCodesQueryInterface
{
    /**
     * Every code of the event, held ones first (by member name), then the free ones.
     *
     * @return list<MinimapperzCodeRow>
     */
    public function codes(string $eventId): array;

    /**
     * The active registrants of the event who hold no code yet.
     *
     * @return list<string>
     */
    public function registrantsWithoutCode(string $eventId): array;
}
