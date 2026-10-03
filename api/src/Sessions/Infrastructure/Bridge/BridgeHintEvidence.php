<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Bridge;

use Archilan\BridgeClient\Slots\Response\Hint;

/**
 * Reads a slot's hint list to tell whether a hint exists for an item or a location (story 41.3). The bridge's free
 * hint is fire-and-forget: this is how a purchase knows the hint was actually given.
 *
 * - `none`: no hint for it;
 * - `open`: a hint whose item is still to be found;
 * - `found`: hints, but all of them already found (nothing left to learn).
 */
final class BridgeHintEvidence
{
    /**
     * @param array<Hint> $hints
     *
     * @return 'none'|'open'|'found'
     */
    public static function forItem(array $hints, int $slot, string $itemName): string
    {
        return self::status(array_filter($hints, static fn (Hint $h): bool => $h->receivingSlot === $slot && 0 === strcasecmp($h->itemName, $itemName)));
    }

    /**
     * @param array<Hint> $hints
     *
     * @return 'none'|'open'|'found'
     */
    public static function forLocation(array $hints, int $slot, int $locationId): string
    {
        return self::status(array_filter($hints, static fn (Hint $h): bool => $h->findingSlot === $slot && $h->locationId === $locationId));
    }

    /**
     * @param array<Hint> $matching
     *
     * @return 'none'|'open'|'found'
     */
    private static function status(array $matching): string
    {
        if ([] === $matching) {
            return 'none';
        }
        foreach ($matching as $hint) {
            if (!$hint->found) {
                return 'open';
            }
        }

        return 'found';
    }
}
