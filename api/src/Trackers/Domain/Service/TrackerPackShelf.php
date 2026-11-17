<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Service;

use App\Trackers\Domain\Entity\TrackerPack;

/**
 * The packs of one game, as players are offered them (story 54.2). It keeps one rule across them: when a game has
 * enabled packs, exactly one of them opens by default; a disabled pack is never the default. The first pack
 * enabled becomes the default; disabling the default hands it to the oldest pack still enabled.
 */
final readonly class TrackerPackShelf
{
    /**
     * @param list<TrackerPack> $gamePacks every pack of the game, $pack included
     *
     * @throws \DomainException tracker_pack_license_unacknowledged
     */
    public static function enable(array $gamePacks, TrackerPack $pack): void
    {
        if ($pack->isEnabled()) {
            return;
        }
        if (!$pack->maySharePack()) {
            throw new \DomainException('tracker_pack_license_unacknowledged');
        }
        $pack->markEnabled(true);
        if (null === self::currentDefault($gamePacks)) {
            $pack->markDefault(true);
        }
    }

    /** @param list<TrackerPack> $gamePacks every pack of the game, $pack included */
    public static function disable(array $gamePacks, TrackerPack $pack): void
    {
        if (!$pack->isEnabled()) {
            return;
        }
        $pack->markEnabled(false);
        if (!$pack->isDefault()) {
            return;
        }
        $pack->markDefault(false);
        $heir = self::oldestEnabled($gamePacks);
        $heir?->markDefault(true);
    }

    /**
     * @param list<TrackerPack> $gamePacks every pack of the game, $pack included
     *
     * @throws \DomainException tracker_pack_not_enabled
     */
    public static function makeDefault(array $gamePacks, TrackerPack $pack): void
    {
        if (!$pack->isEnabled()) {
            throw new \DomainException('tracker_pack_not_enabled');
        }
        foreach ($gamePacks as $other) {
            if ($other !== $pack && $other->isDefault()) {
                $other->markDefault(false);
            }
        }
        $pack->markDefault(true);
    }

    /** @param list<TrackerPack> $gamePacks */
    private static function currentDefault(array $gamePacks): ?TrackerPack
    {
        foreach ($gamePacks as $pack) {
            if ($pack->isEnabled() && $pack->isDefault()) {
                return $pack;
            }
        }

        return null;
    }

    /** @param list<TrackerPack> $gamePacks */
    private static function oldestEnabled(array $gamePacks): ?TrackerPack
    {
        $enabled = array_values(array_filter($gamePacks, static fn (TrackerPack $p): bool => $p->isEnabled()));
        usort($enabled, static fn (TrackerPack $a, TrackerPack $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

        return $enabled[0] ?? null;
    }
}
