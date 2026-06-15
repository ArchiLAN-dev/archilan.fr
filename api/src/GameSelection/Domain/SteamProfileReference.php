<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

/**
 * Pure parser turning a raw Steam input (SteamID64, vanity name, or profile URL)
 * into a normalized reference. No I/O: vanity resolution to a SteamID64 is done
 * by the infrastructure client, not here.
 */
final readonly class SteamProfileReference
{
    public const KIND_STEAMID64 = 'steamid64';
    public const KIND_VANITY = 'vanity';

    private function __construct(
        public string $kind,
        public string $value,
    ) {
    }

    public static function parse(string $raw): ?self
    {
        $input = trim($raw);

        if ('' === $input) {
            return null;
        }

        if (str_contains(strtolower($input), 'steamcommunity.com')) {
            return self::parseUrl($input);
        }

        if (self::isSteamId64($input)) {
            return new self(self::KIND_STEAMID64, $input);
        }

        return self::vanityOrNull($input);
    }

    private static function parseUrl(string $input): ?self
    {
        $url = $input;
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $s): bool => '' !== $s,
        ));

        if (2 !== count($segments)) {
            return null;
        }

        if ('profiles' === $segments[0] && self::isSteamId64($segments[1])) {
            return new self(self::KIND_STEAMID64, $segments[1]);
        }

        if ('id' === $segments[0]) {
            return self::vanityOrNull($segments[1]);
        }

        return null;
    }

    private static function vanityOrNull(string $candidate): ?self
    {
        $vanity = strtolower(trim($candidate));

        if (1 === preg_match('/^[a-z0-9_-]{2,64}$/', $vanity)) {
            return new self(self::KIND_VANITY, $vanity);
        }

        return null;
    }

    private static function isSteamId64(string $value): bool
    {
        return 1 === preg_match('/^7656\d{13}$/', $value);
    }
}
