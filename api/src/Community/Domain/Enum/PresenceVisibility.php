<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

use App\Community\Domain\Service\AudiencePolicy;

/**
 * Who sees that a member is playing right now (story 43.6). Its own enum rather than a wider Audience: `nobody` has
 * no meaning for the profile sections, and would be offered everywhere. The member always sees themselves.
 */
enum PresenceVisibility: string
{
    case Everyone = 'everyone';
    case Members = 'members';
    case Friends = 'friends';
    case Nobody = 'nobody';

    /** What a profile holds until its owner chooses: the presence stays as visible as before the setting. */
    public const self DEFAULT = self::Everyone;

    /** Whether a viewer of this tier (see AudiencePolicy) may see the presence. A block is the caller's to apply. */
    public function shows(string $viewerTier): bool
    {
        if (AudiencePolicy::TIER_SELF === $viewerTier) {
            return true;
        }

        return match ($this) {
            self::Everyone => true,
            self::Members => in_array($viewerTier, [AudiencePolicy::TIER_MEMBER, AudiencePolicy::TIER_FRIEND], true),
            self::Friends => AudiencePolicy::TIER_FRIEND === $viewerTier,
            self::Nobody => false,
        };
    }
}
