<?php

declare(strict_types=1);

namespace App\Community\Application;

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
}
