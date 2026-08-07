<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use App\Identity\Domain\Entity\User;

/**
 * Community's port for acting on a member's Identity-owned access state (story 30.29). Community defines
 * the contract (consumer); an Identity Infrastructure adapter implements it and mutates the `User` - so
 * Community never touches Identity internals (mirrors the 30.26 cross-context trigger, inverted).
 *
 * Each method returns true when the target user exists and the change was applied, false otherwise.
 */
interface MemberModerationGatewayInterface
{
    public function suspendUntil(string $userId, \DateTimeImmutable $until, string $reason): bool;

    public function ban(string $userId, string $reason): bool;

    public function lift(string $userId): bool;

    /**
     * The member's current access state, or null when the account does not exist (or is deleted).
     *
     * Story 36.2: without this the port could only write. Community knew how to sanction a member and
     * had no way to tell whether they already were - so the moderation panel had nothing to show.
     */
    public function currentState(string $userId): ?MemberModerationState;
}
