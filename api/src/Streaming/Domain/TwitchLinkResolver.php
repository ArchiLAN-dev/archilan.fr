<?php

declare(strict_types=1);

namespace App\Streaming\Domain;

/**
 * Pure resolver that extracts a Twitch login from a member's social links.
 *
 * Mirrors the frontend link-type matching (`features/community/social-links.ts`): a link counts as Twitch
 * when its label is the `twitch` type (case-insensitive) or its URL host is `twitch.tv` / `www.twitch.tv`.
 * The login is the first path segment, lowercased, validated against the Twitch login grammar. No I/O - all
 * external values are passed in as parameters (AC-D3).
 */
final class TwitchLinkResolver
{
    /** Twitch logins: 3-25 chars, letters/digits/underscore (see story 7.7 AC3). */
    private const LOGIN_PATTERN = '/^[a-z0-9_]{3,25}$/';

    private const TWITCH_HOSTS = ['twitch.tv', 'www.twitch.tv'];

    /**
     * Returns the first valid Twitch login among the social links, or null when none match.
     *
     * @param list<array{label: string, url: string}> $socialLinks
     */
    public static function resolveLogin(array $socialLinks): ?string
    {
        foreach ($socialLinks as $link) {
            $login = self::loginFromLink($link['label'], $link['url']);
            if (null !== $login) {
                return $login;
            }
        }

        return null;
    }

    private static function loginFromLink(string $label, string $url): ?string
    {
        $url = trim($url);
        if ('' === $url) {
            return null;
        }

        // parse_url needs a scheme to populate the host; a bare "twitch.tv/foo" otherwise lands in the path.
        $normalized = preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'https://'.$url;
        $parts = parse_url($normalized);
        if (false === $parts) {
            return null;
        }

        $host = isset($parts['host']) ? mb_strtolower($parts['host']) : '';
        $isTwitchHost = in_array($host, self::TWITCH_HOSTS, true);
        $isTwitchLabel = 'twitch' === mb_strtolower(trim($label));

        if (!$isTwitchHost && !$isTwitchLabel) {
            return null;
        }

        $candidate = self::firstPathSegment($parts['path'] ?? '');
        if (null === $candidate && $isTwitchLabel && !$isTwitchHost) {
            // Label says Twitch but the URL is a bare handle (host-only) - treat the host as the login.
            $candidate = '' !== $host ? $host : null;
        }

        if (null === $candidate) {
            return null;
        }

        $login = mb_strtolower($candidate);

        return 1 === preg_match(self::LOGIN_PATTERN, $login) ? $login : null;
    }

    private static function firstPathSegment(string $path): ?string
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ('' !== $segment) {
                return $segment;
            }
        }

        return null;
    }
}
