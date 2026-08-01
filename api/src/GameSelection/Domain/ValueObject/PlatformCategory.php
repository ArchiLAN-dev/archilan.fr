<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\ValueObject;

/**
 * Maps raw IGDB platforms (noisy: ~150 entries incl. re-release variants) onto a small,
 * readable set of curated families for catalog categorisation. Matching is done on the
 * platform name with ordered keyword rules (first match wins), so variants like
 * "Super Famicom", "64DD" or "New Nintendo 3DS" collapse into their family. Unmapped
 * platforms fall back to their IGDB name so nothing is silently dropped.
 */
final readonly class PlatformCategory
{
    /**
     * Ordered rules: the first family whose any-needle is contained in the (lowercased)
     * platform name wins. Order matters (e.g. "super nintendo" before "nintendo entertainment").
     *
     * @var list<array{family: string, needles: list<string>}>
     */
    private const array RULES = [
        ['family' => 'Super Nintendo', 'needles' => ['super nintendo', 'super famicom', 'satellaview']],
        ['family' => 'NES', 'needles' => ['nintendo entertainment system', 'family computer', 'famicom']],
        ['family' => 'Nintendo 64', 'needles' => ['nintendo 64', '64dd']],
        ['family' => 'GameCube', 'needles' => ['gamecube']],
        ['family' => 'Wii U', 'needles' => ['wii u']],
        ['family' => 'Wii', 'needles' => ['wii']],
        ['family' => 'Switch', 'needles' => ['switch']],
        ['family' => 'Game Boy Advance', 'needles' => ['game boy advance']],
        ['family' => 'Nintendo 3DS', 'needles' => ['3ds']],
        ['family' => 'Nintendo DS', 'needles' => ['nintendo ds']],
        ['family' => 'Game Boy', 'needles' => ['game boy']],
        // PlayStation before VR so "PlayStation VR" stays under PlayStation.
        ['family' => 'PlayStation', 'needles' => ['playstation', 'psp', 'ps vita', 'playstation vita']],
        ['family' => 'Xbox', 'needles' => ['xbox']],
        ['family' => 'Sega', 'needles' => ['sega', 'dreamcast', 'mega drive', 'genesis', 'saturn', 'game gear']],
        // VR before PC/Mobile (e.g. "visionOS" contains "ios" → must resolve to VR first).
        ['family' => 'VR', 'needles' => ['oculus', 'meta quest', 'quest', 'steamvr', 'gear vr', 'daydream', 'visionos']],
        ['family' => 'Cloud', 'needles' => ['stadia', 'amazon luna', 'geforce now']],
        ['family' => 'Navigateur', 'needles' => ['web browser', 'browser']],
        ['family' => 'MSX', 'needles' => ['msx']],
        ['family' => 'PC', 'needles' => ['windows', 'mac', 'linux', 'dos', 'pc']],
        ['family' => 'Mobile', 'needles' => ['ios', 'android', 'mobile', 'fire tv', 'ouya']],
        ['family' => 'Arcade', 'needles' => ['arcade']],
    ];

    /**
     * The curated families an admin can pick from when overriding a game's platforms
     * (story 9.47). Deliberately a closed vocabulary: the catalog filters are built on it.
     *
     * @return list<string>
     */
    public static function selectableFamilies(): array
    {
        $families = array_map(static fn (array $rule): string => $rule['family'], self::RULES);
        sort($families);

        return $families;
    }

    /**
     * Effective platform families of a game (story 9.47): the admin's explicit choice when
     * there is one, the IGDB-derived list otherwise. IGDB describes the game; the override
     * describes what the Archipelago world actually supports.
     *
     * Single source of this precedence - the entity and the catalog DBAL queries both call
     * it, so no read site can drift.
     *
     * @param list<string>|null                  $override
     * @param list<array{id: int, name: string}> $igdbPlatforms
     *
     * @return list<string>
     */
    public static function resolve(?array $override, array $igdbPlatforms): array
    {
        if (null !== $override && [] !== $override) {
            $cleaned = [];
            foreach ($override as $family) {
                $trimmed = trim($family);
                if ('' !== $trimmed && !in_array($trimmed, $cleaned, true)) {
                    $cleaned[] = $trimmed;
                }
            }
            if ([] !== $cleaned) {
                sort($cleaned);

                return $cleaned;
            }
        }

        return self::families($igdbPlatforms);
    }

    /**
     * @param list<array{id: int, name: string}> $igdbPlatforms
     *
     * @return list<string>
     */
    public static function families(array $igdbPlatforms): array
    {
        $families = [];

        foreach ($igdbPlatforms as $platform) {
            $families[self::familyFor($platform['name'])] = true;
        }

        $result = array_keys($families);
        sort($result);

        return $result;
    }

    private static function familyFor(string $name): string
    {
        $haystack = mb_strtolower(trim($name));

        foreach (self::RULES as $rule) {
            foreach ($rule['needles'] as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $rule['family'];
                }
            }
        }

        return trim($name);
    }
}
