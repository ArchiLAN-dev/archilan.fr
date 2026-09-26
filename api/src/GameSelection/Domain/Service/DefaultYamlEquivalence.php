<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Service;

/**
 * Whether a player's YAML says the same thing as a game's default YAML (stories 38.7 and 38.4).
 *
 * Compared on meaning, not text: the editor may rewrite a template it did not change. Only the game
 * and its option section count - `name`, `description` and `requires` never change what is generated.
 * Within an option, a weight of 0 is never drawn, and a single weighted choice is that choice: so
 * `goal: {astley: 50, clamshells: 0}` and `goal: astley` are the same setting. Keys are compared as
 * strings, a boolean as `true`/`false`, whatever order they come in.
 *
 * Pure: works on YAML already parsed.
 */
final class DefaultYamlEquivalence
{
    /**
     * @param array<mixed> $playerYaml
     * @param array<mixed> $defaultYaml
     */
    public static function isEquivalent(array $playerYaml, array $defaultYaml): bool
    {
        $game = $defaultYaml['game'] ?? null;
        if (!is_string($game) || $game !== ($playerYaml['game'] ?? null)) {
            return false;
        }

        $playerSection = $playerYaml[$game] ?? null;
        $defaultSection = $defaultYaml[$game] ?? null;
        if (!is_array($playerSection) || !is_array($defaultSection)) {
            return false;
        }

        return self::normalizeSection($playerSection) === self::normalizeSection($defaultSection);
    }

    /**
     * The settings of a game section, each reduced to what it means.
     *
     * @param array<mixed> $section
     *
     * @return array<string, mixed>
     */
    public static function normalizeSection(array $section): array
    {
        $normalized = [];
        foreach ($section as $option => $value) {
            $normalized[(string) $option] = self::normalizeValue($value);
        }
        ksort($normalized);

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return self::scalar($value);
        }

        if (self::isWeightMap($value)) {
            $drawn = [];
            foreach ($value as $choice => $weight) {
                $weight = is_numeric($weight) ? (int) $weight : 0;
                if ($weight > 0) {
                    $drawn[self::scalar($choice)] = $weight;
                }
            }
            ksort($drawn);

            // One possible outcome, whatever its weight: that is the setting itself.
            return 1 === \count($drawn) ? self::scalar(array_key_first($drawn)) : $drawn;
        }

        $nested = [];
        foreach ($value as $key => $item) {
            $nested[self::scalar($key)] = self::normalizeValue($item);
        }
        if (!array_is_list($value)) {
            ksort($nested);
        }

        return $nested;
    }

    /**
     * A mapping of choices to integer weights, the shape of almost every option in a template.
     *
     * @param array<mixed> $value
     */
    private static function isWeightMap(array $value): bool
    {
        if ([] === $value || array_is_list($value)) {
            return false;
        }

        return array_all($value, fn ($weight) => is_int($weight));
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            true === $value => 'true',
            false === $value => 'false',
            null === $value => '',
            is_scalar($value) => (string) $value,
            default => json_encode($value, \JSON_THROW_ON_ERROR),
        };
    }
}
