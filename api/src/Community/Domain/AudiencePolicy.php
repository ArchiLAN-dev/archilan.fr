<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Pure decision: can a viewer (resolved to a tier) see a section of a given audience? The viewer ladder
 * is anonymous < authenticated-non-member < member < friend < self (epic §G review #10). `member` is a
 * *live* membership, resolved by the caller (Application) - this stays pure. The `friend` tier lands with
 * the social graph (story 30.7); until then no viewer is ever resolved to it.
 */
final class AudiencePolicy
{
    public const TIER_ANONYMOUS = 'anonymous';
    public const TIER_AUTHENTICATED = 'authenticated';
    public const TIER_MEMBER = 'member';
    public const TIER_FRIEND = 'friend';
    public const TIER_SELF = 'self';

    public static function canView(string $viewerTier, string $audience): bool
    {
        return match ($audience) {
            Audience::PUBLIC => true,
            Audience::MEMBERS => in_array($viewerTier, [self::TIER_MEMBER, self::TIER_FRIEND, self::TIER_SELF], true),
            Audience::FRIENDS => in_array($viewerTier, [self::TIER_FRIEND, self::TIER_SELF], true),
            default => false,
        };
    }
}
