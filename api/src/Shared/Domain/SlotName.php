<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Validation rules for an Archipelago slot / player name (the YAML `name:` field).
 *
 * Allowed: letters, digits, underscore, and the Archipelago placeholders {number}/{player}
 * (and their uppercase {NUMBER}/{PLAYER} variants), which AP substitutes per slot. Everything
 * else - apostrophes, spaces, accents, other punctuation - is rejected: such characters break
 * generation and the in-game/text-client display. Max length mirrors AP's 16-char slot-name limit.
 */
final class SlotName
{
    public const MAX_LENGTH = 16;

    /** A name is a non-empty run of allowed chars and/or AP placeholder tokens. */
    public const PATTERN = '/^(?:[A-Za-z0-9_]|\{(?:number|player|NUMBER|PLAYER)\})+$/';

    public static function isValid(string $name): bool
    {
        return '' !== $name
            && mb_strlen($name) <= self::MAX_LENGTH
            && 1 === preg_match(self::PATTERN, $name);
    }
}
