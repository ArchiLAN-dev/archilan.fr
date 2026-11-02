<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

/**
 * The Minimapperz codes of the events (story 49.2). Handing a code out is atomic: two members never get the same
 * code, and a member never gets two codes for one event, whatever arrives at the same time.
 */
interface MinimapperzCodeRepositoryInterface
{
    /**
     * Adds the codes the event does not have yet.
     *
     * @param list<string> $codes
     *
     * @return int how many were added
     */
    public function addCodes(string $eventId, array $codes, \DateTimeImmutable $now): int;

    /** The code the member holds for the event, if any. */
    public function codeOf(string $eventId, string $userId): ?string;

    /**
     * The member's code for the event: the one they hold, else a free one now handed to them.
     *
     * @return string|null null when they hold none and none is free
     */
    public function assignFreeCode(string $eventId, string $userId, \DateTimeImmutable $now): ?string;

    /**
     * Takes the code back from its member; it is free again.
     *
     * @return bool false when the event has no such code held by anyone
     */
    public function detach(string $eventId, string $code): bool;
}
