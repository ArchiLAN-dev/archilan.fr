<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

/**
 * The slot name Archipelago gives a YAML `name:` in a single-player world (story 17.29).
 *
 * A weekly run generates one world from its template, whose name is often `Player{number}`: the
 * player connects with what Archipelago made of it, not with the stored string. This mirrors
 * `handle_name` in Archipelago's Generate.py for the only player of the world (player 1, first use of
 * the name): `{number}` and `{player}` become 1, `{NUMBER}` and `{PLAYER}` vanish (they only print
 * from 2), the legacy `%number%` / `%player%` spellings behave like their lowercase braces, and the
 * result is trimmed to 16 characters.
 */
final class ArchipelagoSlotNameResolver
{
    private const int MAX_LENGTH = 16;

    /** What Archipelago names a slot whose YAML has no usable `name:`. */
    private const string DEFAULT_NAME = 'Player{number}';

    public static function soloWorld(?string $yamlName): string
    {
        $name = null !== $yamlName && '' !== trim($yamlName) ? $yamlName : self::DEFAULT_NAME;

        $resolved = strtr($name, [
            '%number%' => '1',
            '%player%' => '1',
            '{number}' => '1',
            '{player}' => '1',
            '{NUMBER}' => '',
            '{PLAYER}' => '',
        ]);

        return trim(mb_substr(trim($resolved), 0, self::MAX_LENGTH));
    }
}
