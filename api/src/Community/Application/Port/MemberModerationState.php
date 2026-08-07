<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * A member's Identity-owned access state, as Community reads it back through
 * {@see MemberModerationGatewayInterface} (story 36.2).
 *
 * The port was write-only until now: Community could sanction a member but had no way to tell whether
 * they already were. Defined here, on the consumer side, so Identity stays free to change how it stores
 * the state.
 */
final readonly class MemberModerationState
{
    public function __construct(
        public ?string $suspendedUntil,
        public ?string $bannedAt,
        public ?string $reason,
    ) {
    }
}
