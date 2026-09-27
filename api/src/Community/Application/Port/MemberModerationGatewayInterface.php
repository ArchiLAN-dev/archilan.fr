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

    /**
     * The Discord account linked to the member, or null (story 39.1: mentioned in the staff forum, and the
     * account the sanction reaches on Discord in the next stories of epic 39).
     */
    public function discordIdOf(string $userId): ?string;

    /**
     * The members whose suspension still runs at the given moment, banned ones excepted (story 39.6).
     *
     * @return list<SuspendedMember>
     */
    public function currentlySuspended(\DateTimeImmutable $now): array;

    /** The site account linked to this Discord account, if any (story 39.7). */
    public function userIdForDiscordId(string $discordId): ?string;

    /**
     * The linked accounts banned on the site (story 39.7).
     *
     * @return list<BannedMember>
     */
    public function currentlyBanned(): array;
}
